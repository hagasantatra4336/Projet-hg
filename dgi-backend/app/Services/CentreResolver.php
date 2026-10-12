<?php

namespace App\Services;

use App\Models\Centre;

/**
 * Retrouve un centre de l'application à partir de ce que dit la base source :
 *   - un code officiel (« 21-1-702 »), ou
 *   - un nom : libellé (« CENTRE FISCAL ANTANANARIVO I »), abréviation (« CF TANA I ») ou nom court (« TANA I »).
 * Un nom qui correspond à PLUSIEURS centres (« MAHAJANGA » → centres A et B) est ambigu : il n'est pas résolu
 * (on ne devine pas). Des alias peuvent être déclarés dans config/dgi.php (source.alias_centres).
 */
class CentreResolver
{
    private const PREFIXES = ['CF', 'SRE', 'DRI', 'CENTRE FISCAL', 'DIRECTION REGIONALE DES IMPOTS'];

    /** @var array<string, int|null> clé normalisée => id du centre (null = ambigu) */
    private array $index = [];

    /**
     * @param iterable<array{id: int, nom: string, code?: string|null, abreviation?: string|null}> $centres
     * @param array<string, string> $alias  nom (ou code) de la source => code ou nom d'un centre de l'application
     */
    public function __construct(iterable $centres, array $alias = [])
    {
        $parId = [];

        foreach ($centres as $c) {
            $parId[(int) $c['id']] = $c;

            foreach ([$c['code'] ?? null, $c['abreviation'] ?? null, $c['nom']] as $valeur) {
                $this->ajouter($valeur, (int) $c['id']);
            }
        }

        foreach ($alias as $source => $cible) {
            $id = $this->index[self::normaliser($cible)] ?? null;

            if ($id !== null) {
                $this->index[self::normaliser($source)] = $id;
            }
        }
    }

    public static function depuisBase(): self
    {
        return new self(Centre::get(['id', 'nom', 'code', 'abreviation'])->map->only(['id', 'nom', 'code', 'abreviation'])->all(), (array) config('dgi.source.alias_centres'));
    }

    /** Résout un centre à partir d'un code et/ou d'un nom. Retourne null si introuvable ou ambigu. */
    public function resoudre(?string $nom, ?string $code = null): ?int
    {
        foreach ([$code, $nom] as $valeur) {
            if ($valeur === null || trim($valeur) === '') {
                continue;
            }

            $base = self::normaliser($valeur);

            foreach ([$base, ...array_map(fn ($p) => $p . ' ' . $base, self::PREFIXES)] as $cle) {
                if (array_key_exists($cle, $this->index)) {
                    return $this->index[$cle]; // null si ambigu
                }
            }
        }

        return null;
    }

    private function ajouter(?string $valeur, int $id): void
    {
        if ($valeur === null || trim($valeur) === '') {
            return;
        }

        $cle = self::normaliser($valeur);

        if (array_key_exists($cle, $this->index) && $this->index[$cle] !== $id) {
            $this->index[$cle] = null; // même nom pour deux centres : ambigu
        } elseif (! array_key_exists($cle, $this->index)) {
            $this->index[$cle] = $id;
        }
    }

    /** Majuscules, sans accents, espaces simples. */
    public static function normaliser(string $valeur): string
    {
        $valeur = strtr($valeur, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
            'À' => 'A', 'Â' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ç' => 'C',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', strtoupper($valeur)));
    }
}

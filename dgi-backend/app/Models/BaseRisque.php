<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne de la « base d'analyse risque » : voir la migration create_base_risque. */
class BaseRisque extends Model
{
    public const IR = 'IR';
    public const TVA = 'TVA';
    public const RECOUPEMENT = 'RECOUPEMENT';
    public const DAU = 'DAU';

    protected $table = 'base_risque';

    /** Colonnes d'indicateurs propres à chaque source (seules celles-ci sont écrites par l'import de la source). */
    public const COLONNES = [
        self::IR => ['ca', 'resultat', 'reduction', 'ir_a_payer'],
        self::TVA => ['ca', 'ca_taxable', 'ca_exonere', 'tva_deduc_bl', 'tva_deduc_bi', 'tva_deduc_il', 'tva_deduc_ii',
            'tva_deduc_abl', 'tva_deduc_abi', 'credit_tva', 'ca_taxable_export'],
        self::RECOUPEMENT => ['prestations_de_service', 'achats_effectues', 'achats_consommes', 'produits_locaux_vendus', 'exportations',
            'importations', 'montant_total', 'tva_ded_achat', 'tva_ded_imp', 'marche_public'],
        self::DAU => ['dau_tva_im4', 'dau_caf_ex1'],
    ];

    protected $guarded = [];

    public static function toutesColonnes(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::COLONNES))));
    }

    public function contribuable(): BelongsTo
    {
        return $this->belongsTo(Contribuable::class);
    }
}

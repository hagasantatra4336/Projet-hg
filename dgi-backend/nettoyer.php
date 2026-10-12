<?php
DB::transaction(function () {
    echo App\Models\Alerte::query()->delete() . " alerte(s)\n";
    echo App\Models\Declaration::query()->delete() . " declaration(s)\n";
    echo App\Models\Assujettissement::query()->delete() . " assujettissement(s)\n";
    echo App\Models\Contribuable::query()->delete() . " contribuable(s)\n";
});

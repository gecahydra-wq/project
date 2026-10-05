<?php

use App\Models\FingerlingListing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Species used to be free text, so sellers typed "tilapia" and the Browse
 * filter (which matches "Tilapia") never found those listings. This adds an
 * optional variety (GIFT, Red, Nile...) so a GIFT Tilapia is still a Tilapia
 * to the species filter but can be told apart, and spells every existing
 * species the way the marketplace lists it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('variety', 60)->nullable()->after('species');
        });

        foreach (DB::table('listings')->select('id', 'species', 'title')->get() as $listing) {
            $changes = [];

            $species = FingerlingListing::normalizeSpecies($listing->species);
            if ($species !== $listing->species) {
                $changes['species'] = $species;

                // The title was generated as "<species> Fingerlings", so it
                // carries the same lowercase spelling. Only that exact,
                // generated title is fixed; a seller-written one is left alone.
                if ($listing->title === "{$listing->species} Fingerlings") {
                    $changes['title'] = "{$species} Fingerlings";
                }
            }

            // GIFT was only ever written into the title.
            if (preg_match('/\bGIFT\b/', (string) $listing->title)) {
                $changes['variety'] = 'GIFT';
            }

            if ($changes) {
                DB::table('listings')->where('id', $listing->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        // The species spelling fix is left in place: it is the correct value
        // either way, and the original casing is not worth restoring.
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('variety');
        });
    }
};

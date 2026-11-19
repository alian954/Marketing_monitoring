<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->text('recipient')->change();
        });

        DB::table('notification_outbox')
            ->select(['id', 'recipient'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $outboxRows): void {
                foreach ($outboxRows as $outboxRow) {
                    $recipient = (string) $outboxRow->recipient;

                    try {
                        Crypt::decryptString($recipient);
                    } catch (DecryptException) {
                        DB::table('notification_outbox')
                            ->where('id', $outboxRow->id)
                            ->update(['recipient' => Crypt::encryptString($recipient)]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notification_outbox')
            ->select(['id', 'recipient'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $outboxRows): void {
                foreach ($outboxRows as $outboxRow) {
                    $recipient = (string) $outboxRow->recipient;

                    try {
                        $recipient = Crypt::decryptString($recipient);
                    } catch (DecryptException) {
                    }

                    DB::table('notification_outbox')
                        ->where('id', $outboxRow->id)
                        ->update(['recipient' => $recipient]);
                }
            });

        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->string('recipient', 254)->change();
        });
    }
};

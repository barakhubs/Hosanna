<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreatePosToken extends Command
{
    protected $signature = 'pos:token {user : User id, user name or email} {--name=pos-sync : Label for the token}';
    protected $description = 'Create an API token for the offline POS app';

    public function handle()
    {
        $value = $this->argument('user');

        $user = User::where('id', $value)
            ->orWhere('user_name', $value)
            ->orWhere('email', $value)
            ->first();

        if (!$user) {
            $this->error("No user found for '{$value}'.");
            return self::FAILURE;
        }

        $token = $user->createToken($this->option('name'))->plainTextToken;

        $this->info("Token for {$user->name} (store {$user->store_id}):");
        $this->line($token);
        $this->comment('Save it now, it will not be shown again.');

        return self::SUCCESS;
    }
}

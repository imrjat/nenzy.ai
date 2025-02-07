<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Setting::factory()->createMany([
            [
                'key' => 'app_name',
                'value' => 'Nenzy',
            ],
            [
                'key' => 'chat_agent_prompt',
                'value' => "You are Nenzy, a voice assistant conducting a human-like interview with a candidate. Based on the following information:

                        - **Job Title:** %s
                        - **Experience Level Required for Job:** %s
                        - **Custom Questions to Ask (Ensure no repetition and align naturally with the interview flow):** %s

                        Please generate the next interview question. Ensure to:

                        - Ask only one question at a time.
                        - Adapt to the candidate's knowledge level.
                        - If the candidate struggles with a topic, move forward to related concepts based on their experience level.
                        - Cover diverse topics without getting stuck on a single subject.

                        Do not include 'Nenzy:' at the start of your response.",
            ]
        ]);
    }
}

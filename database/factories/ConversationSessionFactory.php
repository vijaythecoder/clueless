<?php

namespace Database\Factories;

use App\Models\ConversationSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConversationSession>
 */
class ConversationSessionFactory extends Factory
{
    protected $model = ConversationSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 month', 'now');
        $endedAt = $this->faker->optional(0.7)->dateTimeBetween($startedAt, 'now');

        return [
            'user_id' => null, // Single-user desktop app
            'title' => $this->faker->sentence(4),
            'customer_name' => $this->faker->name(),
            'customer_company' => $this->faker->company(),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $endedAt ? $this->faker->numberBetween(60, 3600) : 0,
            'template_used' => $this->faker->randomElement(['sales_call', 'discovery', 'demo', 'follow_up', null]),
            'final_intent' => $endedAt ? $this->faker->randomElement(['high', 'medium', 'low', null]) : null,
            'final_buying_stage' => $endedAt ? $this->faker->randomElement(['awareness', 'consideration', 'evaluation', 'purchase', null]) : null,
            'final_engagement_level' => $endedAt ? $this->faker->numberBetween(0, 100) : 50,
            'final_sentiment' => $endedAt ? $this->faker->randomElement(['positive', 'neutral', 'negative', null]) : null,
            'total_transcripts' => $this->faker->numberBetween(0, 100),
            'total_insights' => $this->faker->numberBetween(0, 20),
            'total_topics' => $this->faker->numberBetween(0, 10),
            'total_commitments' => $this->faker->numberBetween(0, 5),
            'total_action_items' => $this->faker->numberBetween(0, 8),
            'ai_summary' => $endedAt ? $this->faker->optional(0.8)->paragraph() : null,
            'user_notes' => $this->faker->optional(0.3)->paragraph(),
        ];
    }

    /**
     * Indicate that the session is ongoing (not ended).
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => null,
            'duration_seconds' => 0,
            'final_intent' => null,
            'final_buying_stage' => null,
            'final_engagement_level' => 50,
            'final_sentiment' => null,
            'ai_summary' => null,
        ]);
    }

    /**
     * Indicate that the session is completed.
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $startedAt = $attributes['started_at'] ?? now()->subHour();
            $endedAt = now();

            return [
                'ended_at' => $endedAt,
                'duration_seconds' => $endedAt->diffInSeconds($startedAt),
                'final_intent' => $this->faker->randomElement(['high', 'medium', 'low']),
                'final_buying_stage' => $this->faker->randomElement(['awareness', 'consideration', 'evaluation', 'purchase']),
                'final_engagement_level' => $this->faker->numberBetween(40, 100),
                'final_sentiment' => $this->faker->randomElement(['positive', 'neutral', 'negative']),
                'ai_summary' => $this->faker->paragraph(),
            ];
        });
    }
}

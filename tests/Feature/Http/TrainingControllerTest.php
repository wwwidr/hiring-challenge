<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class TrainingControllerTest extends TestCase
{
    public function test_training_page_loads(): void
    {
        $response = $this->get('/training');

        $response->assertStatus(200);
        $response->assertSee('Weight Training');
        $response->assertSee('Generate Training Set');
    }

    public function test_generate_training_set_with_default_data(): void
    {
        $response = $this->post('/training/generate', [
            '_token' => csrf_token(),
        ]);

        $response->assertStatus(200);
        $response->assertSee('Correct?');
        $response->assertSee('Validate');
    }

    public function test_calibrate_with_labeled_data(): void
    {
        $response = $this->post('/training/calibrate', [
            '_token' => csrf_token(),
            'samples' => [
                ['agreement' => 1.0, 'authority' => 0.9, 'completeness' => 1.0, 'recency' => 0.5, 'is_correct' => 1],
                ['agreement' => 0.5, 'authority' => 0.7, 'completeness' => 0.67, 'recency' => 0.5, 'is_correct' => 0],
                ['agreement' => 1.0, 'authority' => 0.9, 'completeness' => 1.0, 'recency' => 0.5, 'is_correct' => 1],
                ['agreement' => 0.0, 'authority' => 0.5, 'completeness' => 0.33, 'recency' => 0.5, 'is_correct' => 0],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertSee('Calibration Result');
        $response->assertSee('Previous Weights');
        $response->assertSee('New Weights');
    }
}

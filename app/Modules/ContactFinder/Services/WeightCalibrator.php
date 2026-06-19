<?php

namespace App\Modules\ContactFinder\Services;

class WeightCalibrator
{
    private const FEATURES = ['agreement', 'authority', 'completeness', 'recency'];
    private const LEARNING_RATE = 0.1;
    private const EPOCHS = 1000;

    /**
     * Fit logistic regression on labeled data and return normalized weights.
     *
     * @param array<int, array{agreement: float, authority: float, completeness: float, recency: float, is_correct: int}> $data
     * @return array{agreement: float, authority: float, completeness: float, recency: float}
     */
    public function fit(array $data): array
    {
        $coefficients = [0.0, 0.0, 0.0, 0.0];
        $bias = 0.0;

        for ($epoch = 0; $epoch < self::EPOCHS; $epoch++) {
            $gradients = [0.0, 0.0, 0.0, 0.0];
            $biasGradient = 0.0;

            foreach ($data as $sample) {
                $linearCombination = $bias;

                foreach (self::FEATURES as $featureIndex => $featureName) {
                    $linearCombination += $coefficients[$featureIndex] * $sample[$featureName];
                }

                $prediction = 1.0 / (1.0 + exp(-$linearCombination));
                $error = $prediction - $sample['is_correct'];

                foreach (self::FEATURES as $featureIndex => $featureName) {
                    $gradients[$featureIndex] += $error * $sample[$featureName];
                }

                $biasGradient += $error;
            }

            $count = count($data);

            foreach (self::FEATURES as $featureIndex => $featureName) {
                $coefficients[$featureIndex] -= self::LEARNING_RATE * ($gradients[$featureIndex] / $count);
            }

            $bias -= self::LEARNING_RATE * ($biasGradient / $count);
        }

        return $this->normalizeCoefficients($coefficients);
    }

    /**
     * @param array<int, array{agreement: float, authority: float, completeness: float, recency: float, is_correct: int}> $data
     * @param array{agreement: float, authority: float, completeness: float, recency: float} $weights
     */
    public function evaluateAccuracy(array $data, array $weights): float
    {
        $threshold = config('enrichment.threshold', 70);
        $correct = 0;

        foreach ($data as $sample) {
            $score = (int) round((
                $weights['agreement'] * $sample['agreement']
                + $weights['authority'] * $sample['authority']
                + $weights['completeness'] * $sample['completeness']
                + $weights['recency'] * $sample['recency']
            ) * 100);

            $predicted = $score >= $threshold ? 1 : 0;

            if ($predicted === $sample['is_correct']) {
                $correct++;
            }
        }

        return count($data) > 0 ? $correct / count($data) : 0.0;
    }

    /**
     * @param array{agreement: float, authority: float, completeness: float, recency: float} $weights
     */
    public function applyWeights(array $weights): void
    {
        $configPath = config_path('enrichment.php');
        $content = file_get_contents($configPath);

        foreach (self::FEATURES as $feature) {
            $content = preg_replace(
                "/'{$feature}'\s*=>\s*[\d.]+/",
                "'{$feature}' => {$weights[$feature]}",
                $content,
            );
        }

        file_put_contents($configPath, $content);
    }

    /**
     * @param float[] $coefficients
     * @return array{agreement: float, authority: float, completeness: float, recency: float}
     */
    private function normalizeCoefficients(array $coefficients): array
    {
        $absoluteCoefficients = array_map('abs', $coefficients);
        $sum = array_sum($absoluteCoefficients);

        if ($sum === 0.0) {
            return config('enrichment.weights');
        }

        $normalizedWeights = [];

        foreach (self::FEATURES as $featureIndex => $featureName) {
            $normalizedWeights[$featureName] = round($absoluteCoefficients[$featureIndex] / $sum, 2);
        }

        $weightSum = array_sum($normalizedWeights);

        if ($weightSum !== 1.0) {
            $normalizedWeights['agreement'] += round(1.0 - $weightSum, 2);
        }

        return $normalizedWeights;
    }
}

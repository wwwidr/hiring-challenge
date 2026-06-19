<?php

namespace App\Modules\ContactFinder\Console;

use Illuminate\Console\Command;

class CalibrateWeightsCommand extends Command
{
    protected $signature = 'contacts:calibrate-weights
                            {csv : Path to labeled data CSV (columns: agreement_score, authority_score, completeness_score, recency_score, is_correct)}
                            {--apply : Apply the new weights to config/enrichment.php}';

    protected $description = 'Calibrate confidence scoring weights using labeled data via logistic regression';

    public function handle(): int
    {
        $csvPath = $this->argument('csv');

        if (!file_exists($csvPath)) {
            $this->error("Labeled data file not found: {$csvPath}");

            return self::FAILURE;
        }

        $data = $this->loadLabeledData($csvPath);

        if (count($data) < 20) {
            $this->error('Need at least 20 labeled samples for calibration. Got: ' . count($data));

            return self::FAILURE;
        }

        $this->info('Loaded ' . count($data) . ' labeled samples.');
        $this->newLine();

        $currentWeights = config('enrichment.weights');
        $this->displayWeights('Current weights', $currentWeights);

        $splitIndex = (int) (count($data) * 0.8);
        $trainData = array_slice($data, 0, $splitIndex);
        $testData = array_slice($data, $splitIndex);

        $this->info("Train set: {$splitIndex} | Test set: " . count($testData));
        $this->newLine();

        $newWeights = $this->fitLogisticRegression($trainData);
        $this->displayWeights('Proposed weights', $newWeights);

        $currentAccuracy = $this->evaluateAccuracy($testData, $currentWeights);
        $proposedAccuracy = $this->evaluateAccuracy($testData, $newWeights);

        $this->newLine();
        $this->info('Accuracy on test set:');
        $this->line("  Current weights:  " . number_format($currentAccuracy * 100, 1) . '%');
        $this->line("  Proposed weights: " . number_format($proposedAccuracy * 100, 1) . '%'
            . ' (' . ($proposedAccuracy > $currentAccuracy ? '+' : '') . number_format(($proposedAccuracy - $currentAccuracy) * 100, 1) . '%)');

        $this->newLine();

        if ($proposedAccuracy > $currentAccuracy) {
            $this->info('Recommendation: APPLY new weights.');
        } else {
            $this->warn('Recommendation: KEEP current weights (proposed are not better).');
        }

        $this->newLine();
        $this->displayPythonAlternative($csvPath);

        if ($this->option('apply') && $proposedAccuracy > $currentAccuracy) {
            $this->applyWeights($newWeights);
            $this->info('Weights updated in config/enrichment.php');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{agreement: float, authority: float, completeness: float, recency: float, is_correct: int}>
     */
    private function loadLabeledData(string $csvPath): array
    {
        $data = [];
        $handle = fopen($csvPath, 'r');

        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $requiredColumns = ['agreement_score', 'authority_score', 'completeness_score', 'recency_score', 'is_correct'];
        $indices = [];

        foreach ($requiredColumns as $column) {
            $index = array_search($column, $headers);

            if ($index === false) {
                fclose($handle);
                $this->error("Missing required column: {$column}");

                return [];
            }

            $indices[$column] = $index;
        }

        while (($row = fgetcsv($handle)) !== false) {
            $data[] = [
                'agreement' => (float) $row[$indices['agreement_score']],
                'authority' => (float) $row[$indices['authority_score']],
                'completeness' => (float) $row[$indices['completeness_score']],
                'recency' => (float) $row[$indices['recency_score']],
                'is_correct' => (int) $row[$indices['is_correct']],
            ];
        }

        fclose($handle);

        shuffle($data);

        return $data;
    }

    /**
     * Simple gradient descent logistic regression.
     *
     * @param array<int, array{agreement: float, authority: float, completeness: float, recency: float, is_correct: int}> $data
     * @return array{agreement: float, authority: float, completeness: float, recency: float}
     */
    private function fitLogisticRegression(array $data): array
    {
        $coefficients = [0.0, 0.0, 0.0, 0.0];
        $bias = 0.0;
        $learningRate = 0.1;
        $epochs = 1000;
        $features = ['agreement', 'authority', 'completeness', 'recency'];

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradients = [0.0, 0.0, 0.0, 0.0];
            $biasGradient = 0.0;

            foreach ($data as $sample) {
                $linearCombination = $bias;

                foreach ($features as $featureIndex => $featureName) {
                    $linearCombination += $coefficients[$featureIndex] * $sample[$featureName];
                }

                $prediction = 1.0 / (1.0 + exp(-$linearCombination));
                $error = $prediction - $sample['is_correct'];

                foreach ($features as $featureIndex => $featureName) {
                    $gradients[$featureIndex] += $error * $sample[$featureName];
                }

                $biasGradient += $error;
            }

            $count = count($data);

            foreach ($features as $featureIndex => $featureName) {
                $coefficients[$featureIndex] -= $learningRate * ($gradients[$featureIndex] / $count);
            }

            $bias -= $learningRate * ($biasGradient / $count);
        }

        $absoluteCoefficients = array_map('abs', $coefficients);
        $sum = array_sum($absoluteCoefficients);

        if ($sum === 0.0) {
            return config('enrichment.weights');
        }

        $normalizedWeights = [];

        foreach ($features as $featureIndex => $featureName) {
            $normalizedWeights[$featureName] = round($absoluteCoefficients[$featureIndex] / $sum, 2);
        }

        $weightSum = array_sum($normalizedWeights);

        if ($weightSum !== 1.0) {
            $normalizedWeights['agreement'] += round(1.0 - $weightSum, 2);
        }

        return $normalizedWeights;
    }

    /**
     * @param array<int, array{agreement: float, authority: float, completeness: float, recency: float, is_correct: int}> $data
     * @param array{agreement: float, authority: float, completeness: float, recency: float} $weights
     */
    private function evaluateAccuracy(array $data, array $weights): float
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
    private function displayWeights(string $label, array $weights): void
    {
        $this->info("{$label}:");
        $this->line("  agreement:    {$weights['agreement']}");
        $this->line("  authority:    {$weights['authority']}");
        $this->line("  completeness: {$weights['completeness']}");
        $this->line("  recency:      {$weights['recency']}");
    }

    /**
     * @param array{agreement: float, authority: float, completeness: float, recency: float} $weights
     */
    private function applyWeights(array $weights): void
    {
        $configPath = config_path('enrichment.php');
        $content = file_get_contents($configPath);

        $content = preg_replace(
            "/'agreement'\s*=>\s*[\d.]+/",
            "'agreement' => {$weights['agreement']}",
            $content,
        );
        $content = preg_replace(
            "/'authority'\s*=>\s*[\d.]+/",
            "'authority' => {$weights['authority']}",
            $content,
        );
        $content = preg_replace(
            "/'completeness'\s*=>\s*[\d.]+/",
            "'completeness' => {$weights['completeness']}",
            $content,
        );
        $content = preg_replace(
            "/'recency'\s*=>\s*[\d.]+/",
            "'recency' => {$weights['recency']}",
            $content,
        );

        file_put_contents($configPath, $content);
    }

    private function displayPythonAlternative(string $csvPath): void
    {
        $this->line('<fg=gray>Alternative: use scikit-learn for more robust calibration:</>');
        $this->newLine();
        $this->line('<fg=gray>python3 -c "');
        $this->line("import csv, json, sys");
        $this->line("from sklearn.linear_model import LogisticRegression");
        $this->line("import numpy as np");
        $this->line("data = list(csv.DictReader(open('{$csvPath}')))");
        $this->line("X = np.array([[float(r['agreement_score']), float(r['authority_score']),");
        $this->line("               float(r['completeness_score']), float(r['recency_score'])] for r in data])");
        $this->line("y = np.array([int(r['is_correct']) for r in data])");
        $this->line("model = LogisticRegression().fit(X, y)");
        $this->line("w = np.abs(model.coef_[0]); w = w / w.sum()");
        $this->line("print(json.dumps(dict(zip(['agreement','authority','completeness','recency'], w.round(2).tolist()))))");
        $this->line('"</>');
    }
}

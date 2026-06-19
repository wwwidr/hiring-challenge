<?php

namespace App\Http\Controllers;

use App\Modules\ContactFinder\Services\EnrichmentPipeline;
use App\Modules\ContactFinder\Services\WeightCalibrator;
use App\Modules\ContactFinder\ValueObjects\ScoredContact;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    public function __construct(
        private readonly EnrichmentPipeline $enrichmentPipeline,
        private readonly WeightCalibrator $weightCalibrator,
    ) {}

    public function index()
    {
        return view('training.index');
    }

    public function generate(Request $request)
    {
        $csvPath = $this->resolveCsvPath($request);

        if ($csvPath === null) {
            return redirect()->route('training.index')
                ->with('error', 'CSV file could not be processed.');
        }

        try {
            $results = $this->enrichmentPipeline->process($csvPath);
        } catch (\Throwable $exception) {
            return redirect()->route('training.index')
                ->with('error', 'Pipeline processing issue: ' . $exception->getMessage());
        } finally {
            $this->cleanUpTempFile($request, $csvPath);
        }

        $percentage = max(1, min(100, (int) $request->input('percentage', 20)));
        $sampleSize = max(1, (int) ceil(count($results) * ($percentage / 100)));
        $shuffledResults = $results;
        shuffle($shuffledResults);
        $trainingSubset = array_slice($shuffledResults, 0, $sampleSize);

        $trainingData = array_map(function (ScoredContact $contact) {
            $output = $contact->toArray();
            $output['component_scores'] = $contact->componentScores;

            return $output;
        }, $trainingSubset);

        return view('training.index', [
            'trainingData' => $trainingData,
            'currentWeights' => config('enrichment.weights'),
            'totalCompanies' => count($results),
            'samplePercentage' => $percentage,
        ]);
    }

    public function calibrate(Request $request)
    {
        $samples = $request->input('samples', []);

        if (empty($samples)) {
            return redirect()->route('training.index')
                ->with('error', 'No labeled samples received.');
        }

        $labeledData = [];

        foreach ($samples as $sample) {
            $labeledData[] = [
                'agreement' => (float) ($sample['agreement'] ?? 0),
                'authority' => (float) ($sample['authority'] ?? 0),
                'completeness' => (float) ($sample['completeness'] ?? 0),
                'recency' => (float) ($sample['recency'] ?? 0),
                'is_correct' => (int) ($sample['is_correct'] ?? 0),
            ];
        }

        $previousWeights = config('enrichment.weights');
        $newWeights = $this->weightCalibrator->fit($labeledData);

        $currentAccuracy = $this->weightCalibrator->evaluateAccuracy($labeledData, $previousWeights);
        $proposedAccuracy = $this->weightCalibrator->evaluateAccuracy($labeledData, $newWeights);

        $applied = false;

        if ($proposedAccuracy > $currentAccuracy) {
            $this->weightCalibrator->applyWeights($newWeights);
            $applied = true;
        }

        $correctCount = count(array_filter($labeledData, fn (array $sample) => $sample['is_correct'] === 1));

        return view('training.calibration-result', [
            'previousWeights' => $previousWeights,
            'newWeights' => $newWeights,
            'applied' => $applied,
            'sampleCount' => count($labeledData),
            'correctCount' => $correctCount,
        ]);
    }

    private function resolveCsvPath(Request $request): ?string
    {
        if ($request->hasFile('csv_file') && $request->file('csv_file')->isValid()) {
            return $request->file('csv_file')->getRealPath();
        }

        $defaultPath = base_path('challenge/data/companies.csv');

        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        return null;
    }

    private function cleanUpTempFile(Request $request, string $csvPath): void
    {
        if ($request->hasFile('csv_file') && file_exists($csvPath) && str_starts_with($csvPath, sys_get_temp_dir())) {
            @unlink($csvPath);
        }
    }
}

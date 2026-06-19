<?php

namespace App\Http\Controllers;

use App\Modules\ContactFinder\Services\EnrichmentPipeline;
use App\Modules\ContactFinder\ValueObjects\ScoredContact;
use Illuminate\Http\Request;

class EnrichmentController extends Controller
{
    private const PER_PAGE = 20;
    private const RESULTS_DIR = 'enrichment_runs';

    public function __construct(
        private readonly EnrichmentPipeline $enrichmentPipeline,
    ) {}

    public function index()
    {
        $defaultPath = base_path('challenge/data/companies.csv');
        $history = $this->loadRunHistory();

        return view('enrichment.index', [
            'defaultCompanyCount' => $this->countCsvRows($defaultPath),
            'history' => $history,
        ]);
    }

    public function run(Request $request)
    {
        $csvPath = $this->resolveCsvPath($request);

        if ($csvPath === null) {
            return redirect()->route('enrichment.index')
                ->with('error', 'CSV file could not be processed.');
        }

        $csvFilename = $request->hasFile('csv_file')
            ? $request->file('csv_file')->getClientOriginalName()
            : 'companies.csv (demo)';

        try {
            $results = $this->enrichmentPipeline->process($csvPath);
        } catch (\Throwable $exception) {
            return redirect()->route('enrichment.index')
                ->with('error', 'Pipeline processing issue: ' . $exception->getMessage());
        } finally {
            $this->cleanUpTempFile($request, $csvPath);
        }

        $resultArrays = array_map(fn (ScoredContact $contact) => $contact->toArray(), $results);
        $summary = $this->buildSummary($results);

        $runId = $this->storeRun($resultArrays, $summary, $csvFilename);

        return redirect()->route('enrichment.results', ['run' => $runId]);
    }

    public function results(Request $request)
    {
        $runId = $request->input('run');
        $cached = $runId ? $this->loadRun($runId) : $this->loadLatestRun();

        if ($cached === null) {
            return redirect()->route('enrichment.index')
                ->with('error', 'No results available. Run the pipeline first.');
        }

        $resultArrays = $cached['results'];
        $summary = $cached['summary'];

        $roles = $this->extractUniqueValues($resultArrays, 'contact_role');
        $statuses = $this->extractUniqueValues($resultArrays, 'verification_status');

        $filters = [
            'search' => $request->input('search', ''),
            'role' => $request->input('role', ''),
            'status' => $request->input('status', ''),
            'sort' => $request->input('sort', 'score'),
            'dir' => $request->input('dir', 'desc'),
        ];

        $filtered = $this->applyFilters($resultArrays, $filters);
        $sorted = $this->applySort($filtered, $filters['sort'], $filters['dir']);

        $page = max(1, (int) $request->input('page', 1));
        $paginated = $this->paginate($sorted, $page);

        return view('enrichment.index', [
            'results' => $paginated['items'],
            'summary' => $summary,
            'pagination' => $paginated,
            'roles' => $roles,
            'statuses' => $statuses,
            'filters' => $filters,
            'currentRunId' => $cached['id'] ?? $runId,
            'currentRunMeta' => $cached['meta'] ?? null,
            'history' => $this->loadRunHistory(),
            'defaultCompanyCount' => $this->countCsvRows(base_path('challenge/data/companies.csv')),
        ]);
    }

    public function deleteRun(Request $request, string $runId)
    {
        $directory = $this->getRunsDirectory();
        $filePath = $directory . DIRECTORY_SEPARATOR . $runId . '.json';

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return redirect()->route('enrichment.index')
            ->with('success', 'Run deleted successfully.');
    }

    public function verifyContact(Request $request)
    {
        $runId = $request->input('run_id');
        $companyName = $request->input('company_name');

        if (!$runId || !$companyName) {
            return redirect()->route('enrichment.index')
                ->with('error', 'Missing parameters for verification.');
        }

        $filePath = $this->getRunsDirectory() . DIRECTORY_SEPARATOR . $runId . '.json';

        if (!file_exists($filePath)) {
            return redirect()->route('enrichment.index')
                ->with('error', 'Run not found.');
        }

        $data = json_decode(file_get_contents($filePath), true);

        if (!is_array($data) || !isset($data['results'])) {
            return redirect()->route('enrichment.index')
                ->with('error', 'Corrupted run data.');
        }

        $updated = false;

        foreach ($data['results'] as &$result) {
            if ($result['company_name'] === $companyName && $result['verification_status'] !== 'verified') {
                $result['verification_status'] = 'verified';
                $result['needs_human_review'] = false;
                $result['manually_verified'] = true;
                $result['verified_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        unset($result);

        if ($updated) {
            $verifiedCount = count(array_filter($data['results'], fn (array $result) => !$result['needs_human_review']));
            $data['summary']['verified'] = $verifiedCount;
            $data['summary']['needs_review'] = $data['summary']['total'] - $verifiedCount;
            $data['meta']['verified'] = $verifiedCount;

            file_put_contents($filePath, json_encode($data));
        }

        return redirect()->route('enrichment.results', ['run' => $runId])
            ->with('success', "Contact for \"{$companyName}\" marked as verified.");
    }

    /**
     * @param ScoredContact[] $results
     * @return array{total: int, verified: int, needs_review: int, average_score: int}
     */
    private function buildSummary(array $results): array
    {
        $total = count($results);
        $verified = count(array_filter($results, fn (ScoredContact $contact) => !$contact->needsHumanReview));

        return [
            'total' => $total,
            'verified' => $verified,
            'needs_review' => $total - $verified,
            'average_score' => $total > 0
                ? (int) round(array_sum(array_map(fn (ScoredContact $contact) => $contact->confidenceScore, $results)) / $total)
                : 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $results, array $filters): array
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $roleFilter = (string) ($filters['role'] ?? '');
        $statusFilter = (string) ($filters['status'] ?? '');

        if ($search === '' && $roleFilter === '' && $statusFilter === '') {
            return $results;
        }

        return array_values(array_filter($results, function (array $contact) use ($search, $roleFilter, $statusFilter) {
            if ($search !== '') {
                $searchable = strtolower(implode(' ', [
                    (string) ($contact['company_name'] ?? ''),
                    (string) ($contact['contact_name'] ?? ''),
                    (string) ($contact['contact_role'] ?? ''),
                    (string) ($contact['contact_email'] ?? ''),
                    (string) ($contact['contact_phone'] ?? ''),
                ]));
                if (!str_contains($searchable, $search)) {
                    return false;
                }
            }

            if ($roleFilter !== '' && ($contact['contact_role'] ?? '') !== $roleFilter) {
                return false;
            }

            if ($statusFilter !== '' && ($contact['verification_status'] ?? '') !== $statusFilter) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function applySort(array $results, string $sortColumn, string $direction): array
    {
        $columnMap = [
            'company' => 'company_name',
            'contact' => 'contact_name',
            'role' => 'contact_role',
            'score' => 'confidence_score',
            'status' => 'verification_status',
        ];

        $key = $columnMap[$sortColumn] ?? 'confidence_score';

        usort($results, function (array $contactA, array $contactB) use ($key, $direction) {
            $valueA = $contactA[$key] ?? '';
            $valueB = $contactB[$key] ?? '';

            $comparison = is_int($valueA)
                ? $valueA <=> $valueB
                : strcasecmp((string) $valueA, (string) $valueB);

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array{items: array<int, array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    private function paginate(array $results, int $page): array
    {
        $total = count($results);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        return [
            'items' => array_slice($results, $offset, self::PER_PAGE),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return string[]
     */
    private function extractUniqueValues(array $results, string $column): array
    {
        $values = array_unique(array_filter(array_column($results, $column)));
        sort($values);

        return $values;
    }

    private function getRunsDirectory(): string
    {
        $directory = storage_path(self::RESULTS_DIR);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory;
    }

    /**
     * @param array<int, array<string, mixed>> $resultArrays
     * @param array{total: int, verified: int, needs_review: int, average_score: int} $summary
     */
    private function storeRun(array $resultArrays, array $summary, string $csvFilename): string
    {
        $runId = date('Y-m-d_H-i-s') . '_' . substr(md5((string) microtime(true)), 0, 6);
        $directory = $this->getRunsDirectory();

        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . $runId . '.json',
            json_encode([
                'id' => $runId,
                'meta' => [
                    'csv_file' => $csvFilename,
                    'created_at' => date('Y-m-d H:i:s'),
                    'total' => $summary['total'],
                    'verified' => $summary['verified'],
                ],
                'results' => $resultArrays,
                'summary' => $summary,
            ]),
        );

        return $runId;
    }

    /**
     * @return array{id: string, results: array, summary: array, meta: array}|null
     */
    private function loadRun(string $runId): ?array
    {
        $filePath = $this->getRunsDirectory() . DIRECTORY_SEPARATOR . $runId . '.json';

        if (!file_exists($filePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($filePath), true);

        if (!is_array($data) || !isset($data['results'], $data['summary'])) {
            return null;
        }

        return $data;
    }

    /**
     * @return array{id: string, results: array, summary: array, meta: array}|null
     */
    private function loadLatestRun(): ?array
    {
        $history = $this->loadRunHistory();

        if (empty($history)) {
            return null;
        }

        $latestRun = reset($history);

        return $this->loadRun($latestRun['id']);
    }

    /**
     * @return array<int, array{id: string, csv_file: string, created_at: string, total: int, verified: int}>
     */
    private function loadRunHistory(): array
    {
        $directory = $this->getRunsDirectory();
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.json');

        if ($files === false || empty($files)) {
            return [];
        }

        rsort($files);

        $history = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!is_array($data) || !isset($data['id'], $data['meta'])) {
                continue;
            }

            $history[] = [
                'id' => $data['id'],
                'csv_file' => $data['meta']['csv_file'] ?? 'unknown',
                'created_at' => $data['meta']['created_at'] ?? '',
                'total' => $data['meta']['total'] ?? 0,
                'verified' => $data['meta']['verified'] ?? 0,
            ];
        }

        return $history;
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

    private function countCsvRows(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return 0;
        }

        fgetcsv($handle);
        $count = 0;

        while (fgetcsv($handle) !== false) {
            $count++;
        }

        fclose($handle);

        return $count;
    }
}

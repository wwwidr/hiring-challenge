# Contact Finder (Stage B)

This folder is a standalone Python project managed with `uv`.

## Prerequisites

- Python 3.10+
- `uv` installed

## Setup

From repo root:

```bash
cd challenge/main
uv sync
```

This creates `.venv` and installs dependencies from `pyproject.toml`.

## Run the pipeline

From `challenge/main`:

```bash
uv run python contact_finder.py \
  --input ../data/companies.csv \
  --mocks ../mocks/enrichment_responses.json \
  --output ../output/contact_results.csv \
  --provenance-output ../output/contact_provenance.json
```

## Run tests

From `challenge/main`:

```bash
uv run python -m unittest discover -s tests -p 'test_*.py'
```

## Notes

- Confidence threshold behavior is validated in schema: if `confidence_score < 70`, `contact_email_or_phone` must be empty.
- The pipeline writes two outputs:
  - `challenge/output/contact_results.csv`
  - `challenge/output/contact_provenance.json`

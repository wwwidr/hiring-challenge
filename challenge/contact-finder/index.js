const fs = require('fs');
const path = require('path');

const CHALLENGE_ROOT = path.resolve(__dirname, '..');
const INPUT_PATH = path.join(CHALLENGE_ROOT, 'data', 'companies.csv');
const MOCK_PATH = path.join(CHALLENGE_ROOT, 'mocks', 'enrichment_responses.json');
const OUTPUT_PATH = path.join(__dirname, 'output.csv');
const REVIEW_THRESHOLD = 70;

function parseCsv(content) {
  const lines = content.trim().split(/\r?\n/);
  const headers = splitCsvLine(lines.shift());

  return lines.map((line) => {
    const values = splitCsvLine(line);
    return headers.reduce((row, header, index) => {
      row[header] = values[index] || '';
      return row;
    }, {});
  });
}

function splitCsvLine(line) {
  const values = [];
  let current = '';
  let quoted = false;

  for (let index = 0; index < line.length; index += 1) {
    const char = line[index];
    const next = line[index + 1];

    if (char === '"' && quoted && next === '"') {
      current += '"';
      index += 1;
    } else if (char === '"') {
      quoted = !quoted;
    } else if (char === ',' && !quoted) {
      values.push(current);
      current = '';
    } else {
      current += char;
    }
  }

  values.push(current);
  return values;
}

function roleScore(role) {
  const normalized = String(role || '').toLowerCase();

  if (normalized.includes('accounts payable') || normalized.includes('ap manager')) {
    return 20;
  }

  if (normalized.includes('owner') || normalized.includes('founder') || normalized.includes('president')) {
    return 18;
  }

  if (normalized.includes('cfo') || normalized.includes('finance')) {
    return 16;
  }

  if (normalized.includes('office manager') || normalized.includes('manager')) {
    return 12;
  }

  if (normalized.includes('registered agent')) {
    return 4;
  }

  return 0;
}

function normalizeName(name) {
  return String(name || '')
    .toLowerCase()
    .replace(/^dr\.\s+/, '')
    .replace(/\b(robert|bob)\b/g, 'bob')
    .replace(/[^a-z]/g, '');
}

function namesAgree(first, second) {
  if (!first || !second) {
    return false;
  }

  const a = normalizeName(first);
  const b = normalizeName(second);

  return a === b || a.includes(b) || b.includes(a);
}

function sourceUrls(sources) {
  return sources
    .map((source) => source && source.source_url)
    .filter(Boolean);
}

function chooseContact(companyName, providerData) {
  if (!providerData) {
    return result('', '', '', 0, '', true, 'No provider returned a candidate');
  }

  const registry = providerData.registry || null;
  const listing = providerData.listing || null;
  const enrichment = providerData.enrichment || null;
  const urls = sourceUrls([registry, listing, enrichment]);

  const selectedName = registry?.name || listing?.name || '';
  const selectedRole = registry?.role || inferRole(listing?.name, enrichment?.email);
  const contact = enrichment?.email || enrichment?.phone || listing?.phone || '';
  const hasNamedContact = Boolean(selectedName);
  const hasContactDetail = Boolean(contact);
  const hasRegistryContact = Boolean(registry?.name);
  const hasListingContact = Boolean(listing?.name || listing?.phone);
  const hasEnrichmentContact = Boolean(enrichment?.email || enrichment?.phone);
  const independentSources = [hasRegistryContact, hasListingContact, hasEnrichmentContact].filter(Boolean).length;
  const registryListingAgree = namesAgree(registry?.name, listing?.name);
  const phoneAgreement = Boolean(listing?.phone && enrichment?.phone && listing.phone === enrichment.phone);
  const hasAgreement = registryListingAgree || phoneAgreement || independentSources >= 3;

  let score = 25;
  score += hasListingContact ? 15 : 8;
  score += roleScore(selectedRole);
  score += hasContactDetail ? 15 : 0;
  score += urls.length > 0 ? 10 : 0;
  score += hasAgreement ? 10 : independentSources >= 2 ? 5 : 0;

  if (enrichment?.provider_confidence) {
    score += Math.max(-10, Math.min(10, Math.round((enrichment.provider_confidence - 70) / 3)));
  }

  const reviewReasons = [];

  if (!hasContactDetail) {
    reviewReasons.push('missing contact detail');
  }

  if (urls.length === 0) {
    reviewReasons.push('missing provenance');
  }

  if (independentSources < 2) {
    reviewReasons.push('single-source candidate');
    score = Math.min(score, 62);
  }

  if (registry?.name && listing?.name && !registryListingAgree) {
    reviewReasons.push('conflicting contact names');
    score = Math.min(score, 65);
  }

  if (String(selectedRole).toLowerCase().includes('registered agent')) {
    reviewReasons.push('registered agent is not payment-relevant enough');
    score = Math.min(score, 55);
  }

  if (!hasNamedContact && enrichment?.provider_confidence < REVIEW_THRESHOLD) {
    reviewReasons.push('generic low-confidence contact');
    score = Math.min(score, enrichment.provider_confidence);
  }

  score = Math.max(0, Math.min(100, score));

  if (score < REVIEW_THRESHOLD && reviewReasons.length === 0) {
    reviewReasons.push('confidence below threshold');
  }

  const needsReview = score < REVIEW_THRESHOLD || reviewReasons.length > 0;

  return result(
    needsReview ? '' : selectedName,
    needsReview ? '' : selectedRole,
    needsReview ? '' : contact,
    score,
    urls.join('|'),
    needsReview,
    reviewReasons.join('; ') || 'Selected contact is supported by provider evidence'
  );
}

function inferRole(name, email) {
  if (String(email || '').toLowerCase().startsWith('office@')) {
    return 'Office contact';
  }

  if (String(email || '').toLowerCase().startsWith('info@')) {
    return 'General business contact';
  }

  if (name) {
    return 'Business contact';
  }

  return '';
}

function result(contactName, contactRole, contactEmailOrPhone, confidenceScore, source, needsHumanReview, reviewReason) {
  return {
    contact_name: contactName,
    contact_role: contactRole,
    contact_email_or_phone: contactEmailOrPhone,
    confidence_score: confidenceScore,
    source,
    needs_human_review: needsHumanReview ? 'true' : 'false',
    review_reason: reviewReason,
  };
}

function escapeCsv(value) {
  const stringValue = String(value ?? '');

  if (/[",\n]/.test(stringValue)) {
    return `"${stringValue.replace(/"/g, '""')}"`;
  }

  return stringValue;
}

function writeCsv(rows) {
  const headers = [
    'company_name',
    'mailing_address',
    'contact_name',
    'contact_role',
    'contact_email_or_phone',
    'confidence_score',
    'source',
    'needs_human_review',
    'review_reason',
  ];

  const lines = [headers.join(',')];

  for (const row of rows) {
    lines.push(headers.map((header) => escapeCsv(row[header])).join(','));
  }

  fs.writeFileSync(OUTPUT_PATH, `${lines.join('\n')}\n`);
}

function main() {
  const companies = parseCsv(fs.readFileSync(INPUT_PATH, 'utf8'));
  const mockResponses = JSON.parse(fs.readFileSync(MOCK_PATH, 'utf8'));

  const rows = companies.map((company) => ({
    ...company,
    ...chooseContact(company.company_name, mockResponses[company.company_name]),
  }));

  writeCsv(rows);
  console.log(`Wrote ${rows.length} rows to ${path.relative(CHALLENGE_ROOT, OUTPUT_PATH)}`);
}

main();

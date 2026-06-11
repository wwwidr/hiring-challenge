import csv
import json
import queue
import threading

def process_company_data(company_name, mock_responses):
    if company_name not in mock_responses:
        return {
            "contact_name": "", "contact_role": "", "contact_email_or_phone": "",
            "confidence_score": 0, "source": "none", "needs_human_review": True
        }
    
    company_data = mock_responses[company_name]
    registry = company_data.get("registry") or {}
    listing = company_data.get("listing") or {}
    enrichment = company_data.get("enrichment") or {}
    
    best_name = None
    best_role = None
    reg_name = registry.get("name")
    reg_role = registry.get("role", "")
    list_name = listing.get("name")
    
    if reg_role and any(keyword in reg_role.lower() for keyword in ["ap", "accounts payable", "payable"]):
        best_name = reg_name
        best_role = reg_role
    elif reg_role and any(keyword in reg_role.lower() for keyword in ["owner", "founder"]):
        best_name = reg_name
        best_role = reg_role
    elif reg_role and any(keyword in reg_role.lower() for keyword in ["cfo", "finance"]):
        best_name = reg_name
        best_role = reg_role
    elif reg_role and "manager" in reg_role.lower():
        best_name = reg_name
        best_role = reg_role
    elif reg_name:
        best_name = reg_name
        best_role = reg_role or "Contact"
    else:
        best_name = list_name
        best_role = "Contact" if list_name else ""

    base_score = enrichment.get("provider_confidence", 0)
    sources_found = []
    if registry: sources_found.append("registry")
    if listing: sources_found.append("listing")
    if enrichment: sources_found.append("enrichment")
    
    if len(sources_found) >= 2:
        base_score += 15
        
    email = enrichment.get("email") or ""
    if best_name and email:
        first_letter = best_name.split()[0][0].lower()
        if first_letter in email.lower():
            base_score += 10
            
    final_score = min(max(base_score, 0), 100)
    contact_info = email or enrichment.get("phone") or listing.get("phone") or ""
    used_urls = [v["source_url"] for k, v in company_data.items() if v and "source_url" in v]
    source_str = ", ".join(used_urls) if used_urls else "none"
    
    if final_score < 70:
        return {
            "contact_name": best_name or "",
            "contact_role": best_role or "",
            "contact_email_or_phone": "",
            "confidence_score": final_score,
            "source": source_str,
            "needs_human_review": True
        }
    else:
        return {
            "contact_name": best_name or "",
            "contact_role": best_role or "",
            "contact_email_or_phone": contact_info,
            "confidence_score": final_score,
            "source": source_str,
            "needs_human_review": False
        }

def worker_thread(input_q, output_q, mock_responses):
    while True:
        try:
            company_name = input_q.get_nowait()
        except queue.Empty:
            break
        result = process_company_data(company_name, mock_responses)
        result["company_name"] = company_name
        output_q.put(result)
        input_q.task_done()

def writer_thread(output_q, total_count, output_file):
    results = []
    seen_identifiers = set()
    for _ in range(total_count):
        res = output_q.get()
        identifier = res["contact_email_or_phone"]
        if identifier and identifier in seen_identifiers:
            output_q.task_done()
            continue
        if identifier:
            seen_identifiers.add(identifier)
        results.append(res)
        output_q.task_done()
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)

def main():
    try:
        with open("mocks/enrichment_responses.json", "r", encoding="utf-8") as f:
            mock_responses = json.load(f)
    except FileNotFoundError:
        with open("enrichment_responses.json", "r", encoding="utf-8") as f:
            mock_responses = json.load(f)

    companies = []
    try:
        with open("data/companies.csv", "r", encoding="utf-8") as f:
            reader = csv.reader(f)
            next(reader, None)
            for row in reader:
                if row:
                    companies.append(row[0])
    except FileNotFoundError:
        companies = list(mock_responses.keys())

    input_queue = queue.Queue()
    output_queue = queue.Queue()

    for company in companies:
        input_queue.put(company)

    writer = threading.Thread(target=writer_thread, args=(output_queue, len(companies), "output.json"))
    writer.start()

    workers = []
    for _ in range(4):
        t = threading.Thread(target=worker_thread, args=(input_queue, output_queue, mock_responses))
        t.start()
        workers.append(t)

    for t in workers:
        t.join()

    writer.join()

if __name__ == "__main__":
    main()
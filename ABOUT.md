# ABOUT.md

## Project: Autonomous Research Agent

An AI agent that autonomously searches the web, reasons through results, and streams 
live steps to a React frontend using FastAPI and Gemini 2.5 Flash with tool calling.

GitHub: https://github.com/SriLakshmiPolavarapu/Autonomous-Research-Agent

---

**One ambiguity**

I was not sure how much autonomy to give the agent. It could decide when and what to 
search, but I had not defined when to stop. Without a termination condition it kept 
searching indefinitely. I resolved it by adding a max iteration cap and a reasoning 
step where the agent explicitly decides if it has enough information to answer.

**One tradeoff**

I chose SSE over WebSockets. Simpler, one-directional, no overhead, and it fit the 
use case perfectly. The tradeoff was losing mid-stream user interrupts, which I was 
okay with since nothing in the product needed it. If the requirements ever include 
user steering mid-run, that decision would need to be revisited.

**One mistake**

I streamed raw tool call outputs straight to the frontend. Users were seeing JSON 
blobs mid-reasoning, which was confusing. I should have mapped those to readable step 
labels from day one. Fixed it by refactoring the streaming layer to emit structured 
events instead of raw payloads.

**One review comment that changed my mind**

Someone flagged that my FastAPI route was doing too much -- parsing, orchestrating, 
and formatting all in one place. I pushed back initially because it felt like 
over-engineering for a small project. But once I split it out into separate layers, 
adding a second agent type took minutes instead of hours. That convinced me.
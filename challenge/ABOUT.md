# About Me

Hi, I'm Abhaysinh. I'm a full-stack engineer who builds, refactors, and tests daily using tools like Cursor and Claude. I’m not precious about any specific tech stack—whether it’s Python, Node, or Laravel—I just care about shipping resilient code fast and using AI to multiply my output.

## Recent Project Reflections: AI-Powered Investment Research Engine

**The Ambiguity**
When parsing incredibly messy financial documents, the biggest ambiguity I faced was figuring out exactly where the LLM's job ended and the deterministic code began.

**The Tradeoff**
I sacrificed immediate UI feedback for strict backend reliability. Instead of streaming raw LLM tokens directly to the React frontend (which looked fast but was brittle), I forced the Python backend to wait, parse, and validate the LLM's JSON against strict schemas before sending anything over. It added a bit of latency, but it completely eliminated frontend crashes from malformed outputs.

**The Mistake**
Early on, my biggest mistake was trusting the LLM to calculate financial ratios directly from the text. Inevitably, it hallucinated the math.

**The Review Comment That Changed My Mind**
*"Never let the LLM do math. Use the LLM as a fuzzy extraction layer to get the JSON, then use Python to actually calculate the numbers."* 
That fundamentally shifted my mental model, and now I strictly separate deterministic logic from AI reasoning.

---
**Links:**
* Portfolio: [chavda.dev](https://chavda.dev)
* GitHub: [abhaysinhchavda](https://github.com/abhaysinhchavda)
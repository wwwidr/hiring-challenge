# ABOUT.md

## Project: Conversational Vision Assistant
GitHub: https://github.com/aswinjojo/AI-Vision-Assistant
Demo: https://youtu.be/LcbvKIGiKhc
Stack: FastAPI · React/TypeScript · GPT-4o-mini · WebSocket · OpenAI TTS

**One ambiguity:** The spec said "real-time" but didn't define it.
Does that mean low latency per turn, or continuous awareness between
turns? I scoped it as turn based, the right call for a voice
assistant, but documented the WebRTC path I'd take if continuous
scene awareness was needed.

**One tradeoff:** Used SQLite instead of Postgres. Not laziness, a
deliberate choice to keep the demo runnable without Docker or cloud
dependencies. I abstracted storage behind a repository interface so
swapping to Postgres is one line in dependencies.py. Built for change
without over-engineering day one.

**One mistake:** I originally waited for the browser's onend silence
event before sending a turn. That added 1 to 3 seconds of dead air every
turn. Switched to a 600ms debounce on final transcript results, the
single biggest latency win in the whole project.

**One review comment that changed my mind:** A reviewer flagged that
I was re-sending image frames in conversation history. I initially
thought visual context should persist across turns. They were right:
prior images grow token usage quadratically with no real benefit. Now
only text responses are stored in the memory window.

import json, datetime

now = datetime.datetime.now(datetime.timezone.utc)
def iso(mins): return (now - datetime.timedelta(minutes=mins)).strftime('%Y-%m-%dT%H:%M:%S+00:00')
def day(n):    return (now - datetime.timedelta(days=n)).strftime('%Y-%m-%d')

# 14-day sparkline (oldest -> newest)
daily_hits = [62, 48, 75, 90, 58, 103, 84, 71, 96, 120, 88, 77, 110, 94]
daily = [{"date": day(13 - i), "hits": daily_hits[i]} for i in range(14)]

byAgent = [
    {"label": "GPTBot", "hits": 402},
    {"label": "ClaudeBot", "hits": 318},
    {"label": "PerplexityBot", "hits": 211},
    {"label": "Googlebot", "hits": 168},
    {"label": "Google-Extended", "hits": 92},
    {"label": "CCBot", "hits": 64},
    {"label": "Bytespider", "hits": 41},
    {"label": "Script/tool", "hits": 28},
]
byEndpoint = [
    {"label": "/llms.txt", "hits": 540},
    {"label": "/.well-known/discovery.json", "hits": 222},
    {"label": "/llms-full.txt", "hits": 180},
    {"label": "/about.md", "hits": 96},
    {"label": "/.well-known/agent-card.json", "hits": 71},
    {"label": "/.well-known/mcp.json", "hits": 44},
    {"label": "/robots.txt", "hits": 31},
]
totals = {"today": 38, "week": 274, "month": 1180, "all": 1180, "agents": 8}

UA = {
    "GPTBot": "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot",
    "ClaudeBot": "Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)",
    "PerplexityBot": "Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)",
    "Googlebot": "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
    "Google-Extended": "Mozilla/5.0 (compatible; Google-Extended/1.0)",
    "CCBot": "CCBot/2.0 (https://commoncrawl.org/faq/)",
}
# (agent, endpoint, minutes-ago)
rows = [
    ("GPTBot", "/llms.txt", 1), ("ClaudeBot", "/.well-known/discovery.json", 4),
    ("PerplexityBot", "/llms-full.txt", 7), ("GPTBot", "/about.md", 12),
    ("Googlebot", "/robots.txt", 18), ("ClaudeBot", "/llms.txt", 26),
    ("Google-Extended", "/.well-known/agent-card.json", 38), ("GPTBot", "/.well-known/discovery.json", 51),
    ("CCBot", "/llms.txt", 70), ("PerplexityBot", "/.well-known/mcp.json", 95),
    ("ClaudeBot", "/about.md", 133), ("GPTBot", "/llms.txt", 171),
]
recent = [{"endpoint": e, "agent": a, "ua": UA.get(a, a), "at": iso(m)} for (a, e, m) in rows]

out = {"enabled": True, "window": 30, "totals": totals, "byAgent": byAgent,
       "byEndpoint": byEndpoint, "daily": daily, "recent": recent}
open('/tmp/agentimus-activity-sample.json', 'w').write(json.dumps(out))
print("wrote sample activity:", json.dumps(totals))

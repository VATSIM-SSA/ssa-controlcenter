#!/usr/bin/env python3
"""
Fixture mock for the two VATSIM APIs that CC's config actually lets you
redirect on staging: the ATC-bookings API and the StatSim API.

NOT covered: the VATSIM core v2 API. config/vatsim.php only exposes
VATSIM_CORE_API_TOKEN — the core API's base URL is hardcoded in CC's client
class, so a token-only override cannot point it at this mock. Redirecting
core-API calls would need an actual overrides/ file replacing that client
class — out of scope here; leave VATSIM_CORE_API_TOKEN empty on staging and
accept that rating/hours sync sits idle (see PLAN.md §5a).

Usage: python3 server.py            (listens on :8000)
Docker: see docker-compose.mock.yml in this directory.

Seed the two JSON files below from real (sanitised) API responses so the
shapes match exactly — CC's frontend will render whatever they return.
"""
import http.server
import json
import os

FIXTURES_DIR = os.path.join(os.path.dirname(__file__), "fixtures")


def load(name):
    path = os.path.join(FIXTURES_DIR, name)
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path.startswith("/bookings"):
            body = load("bookings.json")
        elif self.path.startswith("/statsim"):
            body = load("statsim.json")
        else:
            self.send_response(404)
            self.end_headers()
            return

        payload = json.dumps(body).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format, *args):
        pass  # quiet — this is a fixture server, not something to monitor


if __name__ == "__main__":
    http.server.HTTPServer(("0.0.0.0", 8000), Handler).serve_forever()

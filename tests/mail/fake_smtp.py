#!/usr/bin/env python3
"""Minimal SMTP sink for the mail tests.

Speaks EHLO/HELO, AUTH PLAIN/LOGIN (user "test@example.com", password
"secret"), MAIL, RCPT, DATA, RSET, NOOP, QUIT. Each accepted message is written
to <outdir>/<n>.eml (dot-unstuffed) with an <n>.json envelope next to it.

Usage: fake_smtp.py <port> <outdir>
"""
import base64
import json
import os
import socketserver
import sys

USER, PASSWORD = "test@example.com", "secret"
OUT = sys.argv[2] if len(sys.argv) > 2 else "/tmp/fake_smtp"
COUNTER = [0]


class Handler(socketserver.StreamRequestHandler):
    def send(self, line):
        self.wfile.write((line + "\r\n").encode())
        self.wfile.flush()

    def readline(self):
        return self.rfile.readline().decode("utf-8", "replace").rstrip("\r\n")

    def handle(self):
        self.send("220 fake.smtp ESMTP ready")
        authed, sender, rcpts = False, None, []
        while True:
            line = self.readline()
            if not line and self.rfile.closed:
                return
            cmd = line.upper()
            if cmd.startswith("EHLO"):
                self.wfile.write(b"250-fake.smtp\r\n250-SIZE 52428800\r\n250-8BITMIME\r\n250 AUTH PLAIN LOGIN\r\n")
                self.wfile.flush()
            elif cmd.startswith("HELO"):
                self.send("250 fake.smtp")
            elif cmd.startswith("AUTH PLAIN"):
                parts = base64.b64decode(line.split(" ", 2)[2]).split(b"\0")
                ok = len(parts) == 3 and parts[1].decode() == USER and parts[2].decode() == PASSWORD
                authed = ok
                self.send("235 2.7.0 Authentication successful" if ok else "535 5.7.8 Authentication credentials invalid")
            elif cmd.startswith("AUTH LOGIN"):
                self.send("334 VXNlcm5hbWU6")
                user = base64.b64decode(self.readline()).decode()
                self.send("334 UGFzc3dvcmQ6")
                pw = base64.b64decode(self.readline()).decode()
                authed = user == USER and pw == PASSWORD
                self.send("235 2.7.0 Authentication successful" if authed else "535 5.7.8 Authentication credentials invalid")
            elif cmd.startswith("MAIL FROM:"):
                if not authed:
                    self.send("530 5.7.0 Authentication required")
                    continue
                sender, rcpts = line[10:].strip(), []
                self.send("250 2.1.0 Ok")
            elif cmd.startswith("RCPT TO:"):
                addr = line[8:].strip().strip("<>")
                if addr.endswith("@reject.test"):
                    self.send("550 5.1.1 Mailbox unavailable")
                else:
                    rcpts.append(addr)
                    self.send("250 2.1.5 Ok")
            elif cmd == "DATA":
                if not rcpts:
                    self.send("503 5.5.1 No recipients")
                    continue
                self.send("354 End data with <CR><LF>.<CR><LF>")
                lines = []
                while True:
                    raw = self.rfile.readline()
                    if raw in (b".\r\n", b".\n", b""):
                        break
                    if raw.startswith(b".."):
                        raw = raw[1:]
                    lines.append(raw)
                COUNTER[0] += 1
                n = COUNTER[0]
                os.makedirs(OUT, exist_ok=True)
                with open(os.path.join(OUT, "%d.eml" % n), "wb") as fh:
                    fh.write(b"".join(lines))
                with open(os.path.join(OUT, "%d.json" % n), "w") as fh:
                    json.dump({"from": sender, "rcpt": rcpts}, fh)
                self.send("250 2.0.0 Ok: queued as %d" % n)
            elif cmd == "RSET":
                sender, rcpts = None, []
                self.send("250 Ok")
            elif cmd == "NOOP":
                self.send("250 Ok")
            elif cmd == "QUIT":
                self.send("221 Bye")
                return
            else:
                self.send("502 5.5.2 Command not recognized")


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 2525
    with Server(("127.0.0.1", port), Handler) as srv:
        srv.serve_forever()

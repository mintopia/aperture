import http from 'node:http';

export function startHttpStub(handler) {
    const requests = [];

    return new Promise((resolve) => {
        const server = http.createServer((req, res) => {
            let raw = '';
            req.on('data', (chunk) => (raw += chunk));
            req.on('end', () => {
                const body = req.headers['content-type']?.includes('json') ? JSON.parse(raw) : raw;
                const recorded = { method: req.method, path: req.url, headers: req.headers, body, raw };
                requests.push(recorded);

                const { status = 200, json } = handler(recorded);
                res.writeHead(status, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify(json ?? {}));
            });
        });

        server.listen(0, '127.0.0.1', () => {
            resolve({
                url: `http://127.0.0.1:${server.address().port}`,
                requests,
                close: () => new Promise((done) => server.close(done)),
            });
        });
    });
}

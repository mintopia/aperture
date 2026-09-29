import zlib from 'node:zlib';
import { artisan } from './fixtures.js';

export function tinker(code) {
    artisan('tinker', `--execute=${code}`);
}

export function makePng(width, height, [r, g, b] = [200, 60, 60]) {
    const crcTable = Array.from({ length: 256 }, (_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        return c >>> 0;
    });
    const crc32 = (buf) => {
        let c = 0xffffffff;
        for (const byte of buf) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
    const chunk = (type, data) => {
        const body = Buffer.concat([Buffer.from(type), data]);
        const out = Buffer.alloc(body.length + 8);
        out.writeUInt32BE(data.length, 0);
        body.copy(out, 4);
        out.writeUInt32BE(crc32(body), body.length + 4);
        return out;
    };

    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;
    ihdr[9] = 2;

    const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: width }, () => [r, g, b]).flat())]);
    const raw = Buffer.concat(Array.from({ length: height }, () => row));

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw)),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

export function resetGeneralSettings() {
    tinker(
        "App\\Models\\Setting::where('code', 'like', 'general.%')->orWhere('code', 'like', 'theme.%')->get()->each->delete();" +
            'app(App\\Services\\LogoService::class)->delete();' +
            'app(App\\Services\\CoverImageService::class)->delete();',
    );
}

export function restoreDetectionSettings() {
    tinker(
        "App\\Models\\Setting::set('dns.check_url', 'DNS check URL', 'http://dns-check.e2e.invalid/{uuid}');" +
            "App\\Models\\Setting::set('dns.warning_message', 'DNS warning message', 'Playwright DNS warning');" +
            "foreach (['detection_endpoint' => 'http://ipv6-check.e2e.invalid/{uuid}', 'jwks_url' => '', 'jwt_audience' => '', 'jwt_issuer' => ''] as $k => $v) { App\\Models\\IntegrationConfig::setValue('ipv6', $k, $v); }" +
            "App\\Models\\Setting::where('code', 'like', 'captive%')->get()->each->delete();",
    );
}

import { readFile } from 'node:fs/promises';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

try {
    const data = new Uint8Array(await readFile(process.argv[2]));
    const pdf = await getDocument({ data, isEvalSupported: false, useSystemFonts: false, disableFontFace: true, verbosity: 0 }).promise;
    let text = '', limited = false;
    for (let number = 1; number <= pdf.numPages; number++) {
        if (number > 1000 || text.length > 2_000_000) { limited = true; break; }
        const page = await pdf.getPage(number);
        const content = await page.getTextContent();
        text += content.items.map(item => item.str ? item.str + (item.hasEOL ? '\n' : ' ') : '').join('') + '\n';
        page.cleanup();
    }
    const result={ text: text.trim().slice(0, 2_000_000), status: limited ? 'partial' : text.trim() ? 'ready' : 'empty', pages: pdf.numPages };
    try { await pdf.destroy(); } catch { /* Extraction is complete; a cleanup failure must not corrupt the result. */ }
    process.stdout.write(JSON.stringify(result));
} catch {
    process.stdout.write(JSON.stringify({ text: null, status: 'failed', pages: null }));
}

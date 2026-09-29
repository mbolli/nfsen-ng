/** Saving files from the page (nfsen/download). */

/** Saves `content` (text or a Blob) as `filename`. */
export function download(content, filename, type = 'text/plain') {
    const url = URL.createObjectURL(content instanceof Blob ? content : new Blob([content], { type }));
    downloadUrl(url, filename);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Saves what `href` points at, such as a chart's PNG data URL, as `filename`. */
export function downloadUrl(href, filename) {
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    document.body.append(a);
    a.click();
    a.remove();
}

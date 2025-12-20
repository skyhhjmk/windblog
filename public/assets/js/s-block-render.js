(function () {
    // S-Block Renderer for Browser (Vditor Preview)
    // Transforms specific markdown blocks into HTML structure

    function parseSBlocks(html) {
        // Debug: Log input HTML to console to help identify parsing issues
        // console.log("S-Block Input HTML:", html);

        // Robust Regex:
        // 1. Matches <p> start tags with optional attributes
        // 2. Matches ::type allowing for whitespace
        // 3. Captures params greedily until </p>
        // 4. Captures content (including newlines) until closing block
        // 5. Matches closing <p> block with ::end
        const regex = /<p[^>]*>\s*::([a-z0-9\-]+)(.*?)<\/p>([\s\S]*?)<p[^>]*>\s*::end\s*<\/p>/gi;

        return html.replace(regex, function (match, type, params, content) {
            // console.log("Match found:", type, params);

            let className = 's-block s-' + type;
            let attrs = `class="${className}"`;
            let tagName = 'div';
            let innerContent = content;

            params = params ? params.trim() : '';

            // --- 1. Handle Grid Columns ---
            if (type === 'grid' && params) {
                // Determine cols from plain number or style
                let colsMatch = params.match(/^(\d+)$/) || params.match(/(\d+)/);
                if (colsMatch) {
                    attrs += ` style="--cols: ${colsMatch[1]}"`;
                }
            }

            // --- 2. Handle Button ::btn [Text](url) ---
            // Case A: Lute renders markdown link as HTML anchor: <a href="...">...</a>
            // Case B: Lute keeps it as text text (less likely in preview but possible)
            if (type === 'btn') {
                // Check if params contain an HTML anchor tag
                // Regex looks for <a ... href="url" ...>text</a>
                // Note: Lute output for [Start](#) is usually <a href="#">Start</a>
                let htmlLinkMatch = params.match(/<a\s+(?:[^>]*?\s+)?href=(["'])(.*?)\1[^>]*>(.*?)<\/a>/i);

                if (htmlLinkMatch) {
                    tagName = 'a';
                    let btnUrl = htmlLinkMatch[2];
                    let btnText = htmlLinkMatch[3];

                    attrs += ` href="${btnUrl}"`;

                    // If the inner content (between ::btn and ::end) is empty (common for one-liners),
                    // use the link text as the button content.
                    let strippedContent = content.replace(/<[^>]+>/g, '').trim();
                    if (!strippedContent) {
                        innerContent = btnText;
                    }
                }
                // Fallback: Check for raw markdown link syntax [Text](url)
                else {
                    let mdLinkMatch = params.match(/^\[([^\]]+)\]\(([^)]+)\)$/);
                    if (mdLinkMatch) {
                        tagName = 'a';
                        let btnText = mdLinkMatch[1];
                        let btnUrl = mdLinkMatch[2];
                        attrs += ` href="${btnUrl}"`;

                        let strippedContent = content.replace(/<[^>]+>/g, '').trim();
                        if (!strippedContent) {
                            innerContent = btnText;
                        }
                    }
                }
            }

            // --- 3. Handle Generic Attributes (key="value") ---
            // Example: ::card title="My Title"
            // Note: Quotes might be HTML encoded by Lute in some cases, but usually not in preview transform?
            // Let's assume standard quotes.
            if (params) {
                let titleMatch = params.match(/title=["'](.*?)["']/);
                if (titleMatch) {
                    attrs += ` data-title="${titleMatch[1]}"`;
                }

                // Allow style="color: red" etc? Maybe too dangerous for XSS, skipping for now.
            }

            return `<${tagName} ${attrs}>${innerContent}</${tagName}>`;
        });
    }

    // Expose to window for usage
    window.renderSBlocks = parseSBlocks;
    // console.log("S-Block Renderer Loaded");
})();

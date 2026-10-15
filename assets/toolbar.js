/**
 * Doska toolbar.
 *
 * Source comments are produced by the PHP bundle. The grammar matches
 * Doska\Twig\Comments. The stack walk follows Annotai: comments give the
 * frame, and data-doska-loc on the element gives the line.
 */
const openRe = /^\s*<([^>]+)>\s+(\S+):(\d+)\s+\((app|vendor|external)\)(?:\s+\|\s+(.+?))?\s*$/;
const closeRe = /^\s*<\/([^>]+)>\s*$/;
const callerRe = /^\s*@caller\s+(\S+):(\d+)\s+\((app|vendor|external)\)\s*$/;

function resolveSource(el) {
    const iterator = document.createNodeIterator(document.documentElement, NodeFilter.SHOW_COMMENT);
    const stack = [];
    let pendingCaller = null;
    let comment;
    while ((comment = iterator.nextNode())) {
        if (!(el.compareDocumentPosition(comment) & Node.DOCUMENT_POSITION_PRECEDING)) {
            break;
        }
        const text = comment.nodeValue ?? "";
        let match;
        if ((match = callerRe.exec(text))) {
            pendingCaller = { file: match[1], line: Number(match[2]), scope: match[3] };
        } else if ((match = openRe.exec(text))) {
            stack.push({
                name: match[1],
                file: match[2],
                line: Number(match[3]),
                scope: match[4],
                extra: match[5] ? match[5].trim() : null,
                caller: pendingCaller,
            });
            pendingCaller = null;
        } else if ((match = closeRe.exec(text))) {
            if (stack.length > 0 && stack[stack.length - 1].name === match[1]) {
                stack.pop();
            }
            pendingCaller = null;
        }
    }

    const loc = el.getAttribute("data-doska-loc");
    let file = null;
    let line = null;
    if (loc) {
        const index = loc.lastIndexOf(":");
        file = loc.slice(0, index);
        line = Number(loc.slice(index + 1));
    } else if (stack.length > 0) {
        const frame = stack[stack.length - 1];
        file = frame.file;
        line = frame.line;
    }

    return { file, line, stack };
}

function frameLabel(frame) {
    if (!frame) {
        return null;
    }
    if (frame.name === "controller") {
        return frame.extra ? frame.extra.split(" route:")[0] : "controller";
    }
    if (frame.name.startsWith("block:")) {
        return frame.name.slice(6);
    }
    const slash = frame.name.lastIndexOf("/");
    return slash === -1 ? frame.name : frame.name.slice(slash + 1);
}

function componentLabel(stack) {
    for (let i = stack.length - 1; i >= 0; i -= 1) {
        const frame = stack[i];
        if (frame.name !== "controller" && !frame.name.startsWith("block:")) {
            return frameLabel(frame);
        }
    }
    return null;
}

function cssPath(el) {
    const parts = [];
    let node = el;
    while (node && node.nodeType === Node.ELEMENT_NODE && node !== document.body && parts.length < 4) {
        let piece = node.tagName.toLowerCase();
        if (node.id) {
            piece += "#" + CSS.escape(node.id);
            parts.unshift(piece);
            break;
        }
        const className = typeof node.className === "string"
            ? node.className.trim().split(/\s+/).filter(Boolean).slice(0, 2)
            : [];
        if (className.length > 0) {
            piece += className.map((name) => "." + CSS.escape(name)).join("");
        }
        parts.unshift(piece);
        node = node.parentElement;
    }
    return parts.join(" > ");
}

function uxDetails(el) {
    const lines = [];
    const stimulus = el.closest("[data-controller]");
    if (stimulus) {
        const names = stimulus.getAttribute("data-controller") || "";
        lines.push("Stimulus:", names.trim() + " on " + stimulus.tagName.toLowerCase() + (stimulus.id ? "#" + stimulus.id : ""));
        const app = window.Stimulus;
        if (app && names.trim()) {
            const identifier = names.trim().split(/\s+/)[0];
            const controller = app.getControllerForElementAndIdentifier?.(stimulus, identifier);
            if (controller && controller.constructor?.values) {
                lines.push("values: " + Object.keys(controller.constructor.values).join(", "));
            }
        }
    }

    const live = el.closest("[data-live-name-value]");
    if (live) {
        lines.push("", "Live Component:", live.getAttribute("data-live-name-value") || "");
        const props = live.getAttribute("data-live-props-value");
        if (props && props.length <= 500) {
            lines.push("props: " + props);
        }
    }

    const frame = el.closest("turbo-frame");
    if (frame) {
        lines.push("", "Turbo frame:", (frame.id || "(no id)") + (frame.getAttribute("src") ? " src=" + frame.getAttribute("src") : ""));
    }

    return lines.join("\n").trim();
}

function treeText(stack) {
    const lines = [];
    for (let i = stack.length - 1; i >= 0; i -= 1) {
        const frame = stack[i];
        if (frame.name === "controller") {
            const controller = frame.extra ? frame.extra.split(" route:")[0] : frame.file;
            lines.push("entrypoint: " + controller);
            break;
        }
        if (frame.caller) {
            lines.push(frame.caller.file + ":" + frame.caller.line + ": " + frame.name);
        } else {
            lines.push(frame.file + ":" + frame.line + ": " + frame.name);
        }
    }
    return lines.join("\n");
}

function definitionText(stack) {
    const seen = new Set();
    const lines = [];
    for (const frame of stack) {
        if (frame.name === "controller" || frame.name.startsWith("block:")) {
            continue;
        }
        const key = frame.name + " " + frame.file;
        if (seen.has(key)) {
            continue;
        }
        seen.add(key);
        lines.push(frame.name + ": " + frame.file + ":" + frame.line);
        if (frame.extra && !frame.extra.includes(" route:")) {
            lines.push(frame.name + " class: " + frame.extra);
        }
    }
    return lines.join("\n");
}

function elementInfo(el) {
    const source = resolveSource(el);
    const text = (el.innerText || "").replace(/\s+/g, " ").trim().slice(0, 200);
    const selected = window.getSelection();
    const selectedText = selected && el.contains(selected.anchorNode) ? selected.toString().trim() : "";
    return {
        tagName: el.tagName.toLowerCase(),
        selector: cssPath(el),
        textContent: text,
        selectedText,
        file: source.file,
        line: source.line,
        stack: source.stack,
        ux: uxDetails(el),
    };
}

function frameworkDetails(info) {
    const parts = [];
    if (info.file) {
        parts.push("The element source is at:", "", info.file + ":" + info.line, "");
    }
    const tree = treeText(info.stack);
    if (tree) {
        parts.push("Component rendering tree (from innermost to outermost):", "", tree, "");
    }
    const definitions = definitionText(info.stack);
    if (definitions) {
        parts.push("Component definition locations:", "", definitions, "");
    }
    if (info.ux) {
        parts.push(info.ux);
    }
    return parts.join("\n").trim();
}

function buildPrompt(config, chips, userText) {
    const references = chips.map((info, index) => {
        const body = [
            "<reference id=\"" + index + "\" type=\"selected_element\">",
            "<description>",
            "The user has selected a " + info.tagName + " element.",
            "</description>",
            "<selector>",
            info.selector,
            "</selector>",
            "<framework-details>",
            frameworkDetails(info),
            "</framework-details>",
        ];
        if (info.textContent) {
            body.push("<text-content>", info.textContent, "</text-content>");
        }
        if (info.selectedText) {
            body.push("<selected-text>", info.selectedText, "</selected-text>");
        }
        body.push("</reference>", "");
        return body.join("\n");
    });

    const page = [
        "<context name=\"current-page\">",
        "The user is looking at a page.",
        "<url>",
        location.href,
        "</url>",
        "<framework-details>",
        "Page render information:",
        "controller: " + (config.controller || ""),
        "source: " + (config.source || ""),
        "route: " + (config.route || ""),
        "</framework-details>",
        "</context>",
    ];

    return [
        "<user_prompt>",
        userText.trim(),
        "</user_prompt>",
        "",
        page.join("\n"),
        "",
        "<references>",
        "",
        references.join("\n"),
        "</references>",
    ].join("\n");
}

function readConfig(host) {
    try {
        return JSON.parse(host.getAttribute("data-config") || "{}");
    } catch {
        return {};
    }
}

const DOCKS = ["top", "right", "bottom", "left"];
const PANEL_FOR_TOOL = { inspect: "composer", a11y: "a11y", settings: "settings" };

function storedDock() {
    try {
        const value = localStorage.getItem("doska.dock");
        return DOCKS.includes(value) ? value : null;
    } catch {
        return null;
    }
}

function storedPanel() {
    try {
        const value = JSON.parse(localStorage.getItem("doska.panel") || "null");
        if (value && Number.isFinite(value.left) && Number.isFinite(value.top)) {
            return { left: value.left, top: value.top };
        }
    } catch {
        return null;
    }
    return null;
}

function inferDock(position) {
    const pos = position || {};
    const has = (edge) => pos[edge] !== null && pos[edge] !== undefined;
    if (has("top") && !has("bottom")) {
        return "top";
    }
    if (has("right") && !has("left")) {
        return "right";
    }
    if (has("left") && !has("bottom") && !has("top")) {
        return "left";
    }
    return "bottom";
}

function resolveDock(config) {
    if (storedDock()) {
        return storedDock();
    }
    if (DOCKS.includes(config.dock)) {
        return config.dock;
    }
    return inferDock(config.position);
}

function isSkipped(el) {
    return Boolean(el.closest("#doska-toolbar, #doska-highlight, #doska-badge, ux-inspector, .sf-toolbar"));
}

function isVisible(el) {
    if (!(el instanceof Element) || isSkipped(el)) {
        return false;
    }
    if (el.closest("[hidden], [aria-hidden='true']")) {
        return false;
    }
    const style = getComputedStyle(el);
    if (style.display === "none" || style.visibility === "hidden" || style.visibility === "collapse") {
        return false;
    }
    if (Number(style.opacity) === 0) {
        return false;
    }
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
}

function accessibleName(el, allowText) {
    const labelledBy = el.getAttribute("aria-labelledby");
    if (labelledBy) {
        const text = labelledBy.split(/\s+/).map((id) => {
            const node = document.getElementById(id);
            return node ? (node.innerText || "").replace(/\s+/g, " ").trim() : "";
        }).filter(Boolean).join(" ");
        if (text) {
            return text;
        }
    }
    const aria = (el.getAttribute("aria-label") || "").trim();
    if (aria) {
        return aria;
    }
    if (el.labels && el.labels.length) {
        const fromLabels = Array.from(el.labels).map((label) => (label.innerText || "").replace(/\s+/g, " ").trim()).filter(Boolean).join(" ");
        if (fromLabels) {
            return fromLabels;
        }
    }
    const wrapping = el.closest("label");
    if (wrapping) {
        const text = (wrapping.innerText || "").replace(/\s+/g, " ").trim();
        if (text) {
            return text;
        }
    }
    if (el.id) {
        const label = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
        const text = label ? (label.innerText || "").replace(/\s+/g, " ").trim() : "";
        if (text) {
            return text;
        }
    }
    const title = (el.getAttribute("title") || "").trim();
    if (title) {
        return title;
    }
    const image = el.querySelector && el.querySelector("img[alt]");
    const alt = image ? (image.getAttribute("alt") || "").trim() : "";
    if (alt) {
        return alt;
    }
    if (allowText) {
        return (el.innerText || "").replace(/\s+/g, " ").trim();
    }
    return "";
}

function controlKind(el) {
    const role = el.getAttribute("role");
    if (role === "button" || role === "link") {
        return role === "button" ? "Button" : "Link";
    }
    const tag = el.tagName.toLowerCase();
    if (tag === "a") {
        return "Link";
    }
    if (tag === "button") {
        return "Button";
    }
    if (tag === "select") {
        return "Select";
    }
    if (tag === "textarea") {
        return "Text area";
    }
    return "Input";
}

function parseColor(value) {
    const match = /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+%?))?\s*\)/.exec(value || "");
    if (!match) {
        return null;
    }
    let alpha = match[4] === undefined ? 1 : Number(match[4].replace("%", ""));
    if (match[4] && match[4].endsWith("%")) {
        alpha /= 100;
    }
    return { r: Number(match[1]), g: Number(match[2]), b: Number(match[3]), a: alpha };
}

function composite(over, color) {
    return {
        r: color.r * color.a + over.r * (1 - color.a),
        g: color.g * color.a + over.g * (1 - color.a),
        b: color.b * color.a + over.b * (1 - color.a),
    };
}

function opaqueBackground(el) {
    const layers = [];
    let node = el;
    while (node && node.nodeType === Node.ELEMENT_NODE) {
        const color = parseColor(getComputedStyle(node).backgroundColor);
        if (color && color.a > 0) {
            layers.push(color);
            if (color.a >= 1) {
                break;
            }
        }
        node = node.parentElement;
    }
    let background = { r: 255, g: 255, b: 255 };
    for (let i = layers.length - 1; i >= 0; i -= 1) {
        background = composite(background, layers[i]);
    }
    return background;
}

function channel(value) {
    const scaled = value / 255;
    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4;
}

function luminance(color) {
    return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b);
}

function contrastRatio(el) {
    const foreground = parseColor(getComputedStyle(el).color);
    if (!foreground) {
        return null;
    }
    const background = opaqueBackground(el);
    const color = foreground.a >= 1 ? foreground : composite(background, foreground);
    const lighter = Math.max(luminance(color), luminance(background));
    const darker = Math.min(luminance(color), luminance(background));
    return (lighter + 0.05) / (darker + 0.05);
}

function issueWhere(el) {
    const source = resolveSource(el);
    if (source.file) {
        return source.file + ":" + source.line;
    }
    return cssPath(el);
}

function scanPage() {
    const issues = [];
    const push = (el, message) => {
        if (issues.length >= 40 || !el) {
            return;
        }
        issues.push({ el, message, where: issueWhere(el), selector: cssPath(el) });
    };

    if (!(document.documentElement.getAttribute("lang") || "").trim()) {
        push(document.documentElement, "The page has no lang attribute.");
    }

    for (const img of document.querySelectorAll("img")) {
        if (isVisible(img) && !img.hasAttribute("alt")) {
            push(img, "Image is missing an alt attribute.");
        }
    }

    for (const el of document.querySelectorAll("a[href], button, [role='button'], [role='link']")) {
        if (!isVisible(el) || accessibleName(el, true)) {
            continue;
        }
        push(el, controlKind(el) + " has no accessible name.");
    }

    for (const el of document.querySelectorAll("input, select, textarea")) {
        const type = (el.getAttribute("type") || "").toLowerCase();
        if (["hidden", "button", "submit", "reset", "image"].includes(type) || !isVisible(el)) {
            continue;
        }
        if (!accessibleName(el, false)) {
            push(el, controlKind(el) + " has no accessible name.");
        }
    }

    const headings = [...document.querySelectorAll("h1, h2, h3, h4, h5, h6")].filter(isVisible);
    let previous = 0;
    for (const heading of headings) {
        const level = Number(heading.tagName.slice(1));
        if (!accessibleName(heading, true)) {
            push(heading, heading.tagName.toLowerCase() + " is empty.");
        }
        if ((previous === 0 && level > 2) || (previous > 0 && level > previous + 1)) {
            const from = previous === 0 ? 1 : previous;
            push(heading, "Heading skips from h" + from + " to h" + level + ".");
        }
        previous = level;
    }

    for (const el of document.querySelectorAll("[tabindex]")) {
        const value = Number(el.getAttribute("tabindex"));
        if (isVisible(el) && value > 0) {
            push(el, "Positive tabindex (" + value + ").");
        }
    }

    for (const frame of document.querySelectorAll("iframe")) {
        if (isVisible(frame) && !(frame.getAttribute("title") || "").trim()) {
            push(frame, "Frame has no title.");
        }
    }

    const counts = new Map();
    for (const el of document.querySelectorAll("[id]")) {
        if (!el.id || isSkipped(el)) {
            continue;
        }
        counts.set(el.id, (counts.get(el.id) || 0) + 1);
    }
    const reportedIds = new Set();
    for (const el of document.querySelectorAll("[id]")) {
        if (!el.id || isSkipped(el) || reportedIds.has(el.id) || counts.get(el.id) < 2) {
            continue;
        }
        reportedIds.add(el.id);
        push(el, 'Duplicate id "' + el.id + '".');
    }

    // Computed color against the opaque background. Stops after 12 hits.
    let contrastHits = 0;
    let measured = 0;
    const textNodes = document.querySelectorAll("p, span, a, button, label, li, td, th, h1, h2, h3, h4, h5, h6, small, strong, em");
    for (const el of textNodes) {
        if (contrastHits >= 12 || issues.length >= 40 || measured >= 400) {
            break;
        }
        if (!isVisible(el)) {
            continue;
        }
        const ownText = [...el.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
        if (!ownText) {
            continue;
        }
        measured += 1;
        const ratio = contrastRatio(el);
        if (!Number.isFinite(ratio)) {
            continue;
        }
        const style = getComputedStyle(el);
        const size = Number.parseFloat(style.fontSize);
        const bold = Number(style.fontWeight) >= 700;
        const large = size >= 24 || (bold && size >= 18.66);
        const minimum = large ? 3 : 4.5;
        if (ratio < minimum) {
            contrastHits += 1;
            push(el, "Contrast " + ratio.toFixed(2) + ":1 is below " + minimum + ":1.");
        }
    }

    return issues;
}

const MARK = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.2" fill="currentColor"/></svg>';
const ICON_INSPECT = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="currentColor"><path d="M13.9093 12.3603L17.0007 20.8537L14.1816 21.8798L11.0902 13.3864L6.91797 16.5422L8.4087 1.63318L19.134 12.0959L13.9093 12.3603Z"></path></svg>';
const ICON_A11Y = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="currentColor"><path d="M4 12C4 7.58172 7.58172 4 12 4C16.4183 4 20 7.58172 20 12C20 16.4183 16.4183 20 12 20C7.58172 20 4 16.4183 4 12ZM12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2ZM12 8.5C12.8284 8.5 13.5 7.82843 13.5 7C13.5 6.17157 12.8284 5.5 12 5.5C11.1716 5.5 10.5 6.17157 10.5 7C10.5 7.82843 11.1716 8.5 12 8.5ZM12 9.49988C10.4427 9.49988 8.82151 9.14306 7.37106 8.57129L6.62891 10.4285C7.70479 10.8579 8.8573 11.1468 9.99989 11.3301C9.99989 12.2004 10.0849 13.0939 9.68317 13.8974L8.10546 17.0528L9.89432 17.9472L11.4471 14.8416C11.5518 14.6322 11.7658 14.5 11.9999 14.5C12.234 14.5 12.448 14.6323 12.5527 14.8416L14.1055 17.9472L15.8943 17.0528L14.3166 13.8974C13.9149 13.0939 13.9999 12.2004 13.9999 11.3301C15.1409 11.1471 16.2818 10.8534 17.359 10.4332C17.3843 10.4233 16.6291 8.57118 16.6264 8.57225C15.1728 9.13901 13.5595 9.49988 12 9.49988Z"></path></svg>';
const ICON_GEAR = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="currentColor"><path d="M3.33946 17.0002C2.90721 16.2515 2.58277 15.4702 2.36133 14.6741C3.3338 14.1779 3.99972 13.1668 3.99972 12.0002C3.99972 10.8345 3.3348 9.824 2.36353 9.32741C2.81025 7.71651 3.65857 6.21627 4.86474 4.99001C5.7807 5.58416 6.98935 5.65534 7.99972 5.072C9.01009 4.48866 9.55277 3.40635 9.4962 2.31604C11.1613 1.8846 12.8847 1.90004 14.5031 2.31862C14.4475 3.40806 14.9901 4.48912 15.9997 5.072C17.0101 5.65532 18.2187 5.58416 19.1346 4.99007C19.7133 5.57986 20.2277 6.25151 20.66 7.00021C21.0922 7.7489 21.4167 8.53025 21.6381 9.32628C20.6656 9.82247 19.9997 10.8336 19.9997 12.0002C19.9997 13.166 20.6646 14.1764 21.6359 14.673C21.1892 16.2839 20.3409 17.7841 19.1347 19.0104C18.2187 18.4163 17.0101 18.3451 15.9997 18.9284C14.9893 19.5117 14.4467 20.5941 14.5032 21.6844C12.8382 22.1158 11.1148 22.1004 9.49633 21.6818C9.55191 20.5923 9.00929 19.5113 7.99972 18.9284C6.98938 18.3451 5.78079 18.4162 4.86484 19.0103C4.28617 18.4205 3.77172 17.7489 3.33946 17.0002ZM8.99972 17.1964C10.0911 17.8265 10.8749 18.8227 11.2503 19.9659C11.7486 20.0133 12.2502 20.014 12.7486 19.9675C13.1238 18.8237 13.9078 17.8268 14.9997 17.1964C16.0916 16.5659 17.347 16.3855 18.5252 16.6324C18.8146 16.224 19.0648 15.7892 19.2729 15.334C18.4706 14.4373 17.9997 13.2604 17.9997 12.0002C17.9997 10.74 18.4706 9.5632 19.2729 8.6665C19.1688 8.4405 19.0538 8.21822 18.9279 8.00021C18.802 7.78219 18.667 7.57148 18.5233 7.36842C17.3457 7.61476 16.0911 7.43414 14.9997 6.80405C13.9083 6.17395 13.1246 5.17768 12.7491 4.03455C12.2509 3.98714 11.7492 3.98646 11.2509 4.03292C10.8756 5.17671 10.0916 6.17364 8.99972 6.80405C7.9078 7.43447 6.65245 7.61494 5.47428 7.36803C5.18485 7.77641 4.93463 8.21117 4.72656 8.66637C5.52881 9.56311 5.99972 10.74 5.99972 12.0002C5.99972 13.2604 5.52883 14.4372 4.72656 15.3339C4.83067 15.5599 4.94564 15.7822 5.07152 16.0002C5.19739 16.2182 5.3324 16.4289 5.47612 16.632C6.65377 16.3857 7.90838 16.5663 8.99972 17.1964ZM11.9997 15.0002C10.3429 15.0002 8.99972 13.6571 8.99972 12.0002C8.99972 10.3434 10.3429 9.00021 11.9997 9.00021C13.6566 9.00021 14.9997 10.3434 14.9997 12.0002C14.9997 13.6571 13.6566 15.0002 11.9997 15.0002ZM11.9997 13.0002C12.552 13.0002 12.9997 12.5525 12.9997 12.0002C12.9997 11.4479 12.552 11.0002 11.9997 11.0002C11.4474 11.0002 10.9997 11.4479 10.9997 12.0002C10.9997 12.5525 11.4474 13.0002 11.9997 13.0002Z"></path></svg>';
const ICON_GRIP = '<svg viewBox="0 0 12 18" width="12" height="18" aria-hidden="true"><g fill="currentColor"><circle cx="3" cy="3" r="1.25"/><circle cx="9" cy="3" r="1.25"/><circle cx="3" cy="9" r="1.25"/><circle cx="9" cy="9" r="1.25"/><circle cx="3" cy="15" r="1.25"/><circle cx="9" cy="15" r="1.25"/></g></svg>';
const ICON_COPY = '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M12.5 3.5v5.2a2 2 0 0 1-2 2H4.5"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M6.6 8.2 4.2 10.7l2.4 2.6"/></svg>';

class DoskaToolbar extends HTMLElement {
    constructor() {
        super();
        this.inspecting = false;
        this.meta = false;
        this.tool = null;
        this.chips = [];
        this.config = {};
        this.dock = "bottom";
        this.panelPos = null;
        this.panelDragged = false;
        this.pinnedIssue = null;
        this.drag = null;
        this.issues = [];
        this.onMove = null;
        this.onClick = null;
        this.onKey = null;
        this.onReflow = null;
    }

    connectedCallback() {
        this.config = readConfig(this);
        this.dock = resolveDock(this.config);
        this.panelPos = storedPanel();
        this.panelDragged = this.panelPos !== null;
        if (!this.highlight || !this.highlight.isConnected) {
            this.attachOverlays();
        }
        if (!this.shadowRoot) {
            const shadow = this.attachShadow({ mode: "open" });
            shadow.innerHTML = this.markup();
            this.bind(shadow);
        }
        this.applyDock();
        const open = this.shadowRoot.querySelector(".panel.open");
        if (open) {
            if (this.panelDragged && this.panelPos) {
                this.place(this.panelPos.left, this.panelPos.top);
            } else {
                this.anchor();
            }
        }
        this.attachPageListeners();
    }

    disconnectedCallback() {
        this.detachPageListeners();
        this.highlight?.remove();
        this.badge?.remove();
        this.highlight = null;
        this.badge = null;
    }

    bind(shadow) {
        this.editor = shadow.querySelector("[data-editor]");
        this.placeholder = shadow.querySelector("[data-placeholder]");
        this.copyLabel = shadow.querySelector("[data-copy-label]");
        this.findingsLabel = shadow.querySelector("[data-findings-label]");
        shadow.addEventListener("click", (event) => this.onShadowClick(event));
        shadow.addEventListener("pointerdown", (event) => this.gripDown(event));
        shadow.addEventListener("pointermove", (event) => this.gripMove(event));
        shadow.addEventListener("pointerup", (event) => this.gripUp(event));
        shadow.addEventListener("pointercancel", (event) => this.gripUp(event));
        this.editor.addEventListener("input", () => this.syncPlaceholder());
    }

    attachPageListeners() {
        this.detachPageListeners();
        this.onMove = (event) => this.hover(event);
        this.onClick = (event) => this.pick(event);
        this.onKey = (event) => this.key(event);
        this.onReflow = () => this.reflowPin();
        document.addEventListener("mousemove", this.onMove, true);
        document.addEventListener("click", this.onClick, true);
        document.addEventListener("keydown", this.onKey, true);
        document.addEventListener("keyup", this.onKey, true);
        window.addEventListener("scroll", this.onReflow, true);
        window.addEventListener("resize", this.onReflow);
    }

    detachPageListeners() {
        if (!this.onMove) {
            return;
        }
        document.removeEventListener("mousemove", this.onMove, true);
        document.removeEventListener("click", this.onClick, true);
        document.removeEventListener("keydown", this.onKey, true);
        document.removeEventListener("keyup", this.onKey, true);
        window.removeEventListener("scroll", this.onReflow, true);
        window.removeEventListener("resize", this.onReflow);
        this.onMove = null;
    }

    attachOverlays() {
        this.highlight = document.createElement("div");
        this.badge = document.createElement("div");
        this.highlight.id = "doska-highlight";
        this.badge.id = "doska-badge";
        Object.assign(this.highlight.style, {
            position: "fixed",
            pointerEvents: "none",
            zIndex: "2147483645",
            border: "1px dashed #2563eb",
            background: "rgba(37, 99, 235, 0.12)",
            display: "none",
            boxSizing: "border-box",
        });
        Object.assign(this.badge.style, {
            position: "fixed",
            pointerEvents: "none",
            zIndex: "2147483645",
            background: "#2563eb",
            color: "#fff",
            font: "12px/16px ui-sans-serif, system-ui, sans-serif",
            padding: "0 6px",
            display: "none",
            maxWidth: "320px",
            whiteSpace: "nowrap",
            overflow: "hidden",
            textOverflow: "ellipsis",
        });
        document.documentElement.append(this.highlight, this.badge);
    }

    markup() {
        return `
<style>
  :host {
    all: initial;
    position: fixed;
    z-index: 2147483646;
    display: block;
    width: max-content;
    font: 13px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    color: #f4f4f5;
    color-scheme: dark;
  }
  :host, :host *, :host *::before, :host *::after { box-sizing: border-box; }
  button { font: inherit; color: inherit; background: transparent; border: 0; padding: 0; }
  .bar {
    display: flex;
    align-items: center;
    width: max-content;
    padding: 4px;
    gap: 2px;
    background: #1c1c20;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .35);
    user-select: none;
  }
  .bar.vertical { flex-direction: column; }
  .mark, .tool {
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    flex: none;
    border-radius: 8px;
    color: #e7e7ea;
  }
  .tool { cursor: pointer; }
  .tool:hover { background: #2a2a30; }
  .tool[aria-pressed="true"] { background: #34343c; color: #fff; }
  .panel {
    position: absolute;
    display: none;
    max-width: calc(100vw - 16px);
    background: #1c1c20;
    border: 1px solid rgba(255, 255, 255, .08);
    border-radius: 12px;
    box-shadow: 0 16px 40px rgba(0, 0, 0, .42);
    color: #f4f4f5;
  }
  .panel.open { display: flex; flex-direction: column; }
  .panel[data-panel="composer"],
  .panel[data-panel="a11y"] { width: 420px; }
  .panel[data-panel="settings"] { width: 260px; }
  .wrap { position: relative; padding: 14px 16px 6px; }
  .editor { min-height: 52px; outline: none; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; }
  .placeholder {
    position: absolute;
    left: 16px;
    top: 14px;
    right: 16px;
    color: #a1a1aa;
    pointer-events: none;
    font-size: 14px;
  }
  .footer { display: flex; align-items: center; gap: 8px; padding: 8px 10px 10px; }
  .grip {
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border: 1px solid #3f3f46;
    border-radius: 8px;
    color: #a1a1aa;
    cursor: grab;
    flex: none;
  }
  .grip:active { cursor: grabbing; }
  .spacer { flex: 1; }
  .discard { cursor: pointer; padding: 6px 8px; color: #e4e4e7; }
  .discard:hover { color: #fff; }
  .copy, .findings {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 8px;
    padding: 7px 12px;
    cursor: pointer;
  }
  .copy { background: #3b82f6; color: #fff; font-weight: 500; }
  .copy:hover { background: #2563eb; }
  .findings { background: transparent; color: #e4e4e7; border: 1px solid #3f3f46; }
  .findings:hover { background: #2a2a30; }
  .chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 22px;
    margin: 0 2px;
    padding: 0 6px;
    background: #2a2a32;
    border: 1px solid #45454f;
    border-radius: 6px;
    font-size: 12px;
    line-height: 20px;
    vertical-align: baseline;
    cursor: pointer;
    color: #f4f4f5;
  }
  .chip span:last-child { color: #a1a1aa; }
  .head { display: flex; align-items: baseline; gap: 8px; padding: 12px 14px 0; }
  .title { font-weight: 600; }
  .count { color: #a1a1aa; font-size: 12px; }
  .issues {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 280px;
    overflow: auto;
    padding: 8px;
  }
  .issue {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    width: 100%;
    text-align: left;
    padding: 8px;
    border-radius: 8px;
    cursor: pointer;
  }
  .issue:hover, .issue.active { background: #2a2a30; }
  .issue strong { font-weight: 600; }
  .issue span { color: #a1a1aa; font-size: 12px; }
  .empty { margin: 0; padding: 12px 6px; color: #a1a1aa; }
  .docks {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    grid-template-areas: ". top ." "left bottom right";
    gap: 8px;
    padding: 12px;
  }
  .dock { border: 1px solid #3f3f46; background: #232328; border-radius: 8px; padding: 10px 8px; cursor: pointer; }
  .dock:hover { background: #2a2a30; }
  .dock[aria-pressed="true"] { border-color: #3b82f6; background: #1d3d66; }
  .dock[data-dock="top"] { grid-area: top; }
  .dock[data-dock="left"] { grid-area: left; }
  .dock[data-dock="bottom"] { grid-area: bottom; }
  .dock[data-dock="right"] { grid-area: right; }
</style>
<div class="panel" data-panel="composer">
  <div class="wrap">
    <div class="placeholder" data-placeholder>Point to elements and describe changes</div>
    <div class="editor" data-editor contenteditable="true" role="textbox" aria-multiline="true" aria-label="Prompt"></div>
  </div>
  <div class="footer">
    <button type="button" class="grip" data-grip aria-label="Drag prompt">${ICON_GRIP}</button>
    <span class="spacer"></span>
    <button type="button" class="discard" data-discard>Discard</button>
    <button type="button" class="copy" data-copy>${ICON_COPY}<span data-copy-label>Copy prompt</span></button>
  </div>
</div>
<div class="panel" data-panel="a11y">
  <div class="head">
    <span class="title">Accessibility</span>
    <span class="count" data-count></span>
  </div>
  <div class="issues" data-issues></div>
  <div class="footer">
    <button type="button" class="grip" data-grip aria-label="Drag accessibility panel">${ICON_GRIP}</button>
    <span class="spacer"></span>
    <button type="button" class="findings" data-findings><span data-findings-label>Copy findings</span></button>
  </div>
</div>
<div class="panel" data-panel="settings">
  <div class="head"><span class="title">Position</span></div>
  <div class="docks">
    <button type="button" class="dock" data-dock="top">Top</button>
    <button type="button" class="dock" data-dock="left">Left</button>
    <button type="button" class="dock" data-dock="bottom">Bottom</button>
    <button type="button" class="dock" data-dock="right">Right</button>
  </div>
  <div class="footer">
    <button type="button" class="grip" data-grip aria-label="Drag position panel">${ICON_GRIP}</button>
  </div>
</div>
<div class="bar">
  <span class="mark" title="Doska">${MARK}</span>
  <button type="button" class="tool" data-tool="inspect" title="Inspect" aria-pressed="false" aria-label="Inspect">${ICON_INSPECT}</button>
  <button type="button" class="tool" data-tool="a11y" title="Accessibility" aria-pressed="false" aria-label="Accessibility">${ICON_A11Y}</button>
  <button type="button" class="tool" data-tool="settings" title="Position" aria-pressed="false" aria-label="Toolbar position">${ICON_GEAR}</button>
</div>`;
    }

    applyDock() {
        const pos = this.config.position || {};
        const inset = (edge, fallback) => {
            const value = pos[edge];
            return value === null || value === undefined ? fallback : Number(value);
        };
        this.style.position = "fixed";
        this.style.zIndex = "2147483646";
        this.style.width = "max-content";
        this.style.maxWidth = "none";
        this.style.margin = "0";
        this.style.transform = "none";
        this.style.filter = "none";
        this.style.overflow = "visible";
        this.style.top = "auto";
        this.style.right = "auto";
        this.style.bottom = "auto";
        this.style.left = "auto";
        if (this.dock === "top") {
            this.style.top = inset("top", 16) + "px";
            this.style.left = inset("left", 16) + "px";
        } else if (this.dock === "right") {
            this.style.right = inset("right", 16) + "px";
            this.style.bottom = inset("bottom", 56) + "px";
        } else if (this.dock === "left") {
            this.style.left = inset("left", 16) + "px";
            this.style.bottom = inset("bottom", 56) + "px";
        } else {
            this.style.bottom = inset("bottom", 52) + "px";
            this.style.left = inset("left", 16) + "px";
        }
        this.shadowRoot.querySelector(".bar").classList.toggle("vertical", this.dock === "left" || this.dock === "right");
        this.paintDocks();
    }

    paintDocks() {
        if (!this.shadowRoot) {
            return;
        }
        for (const button of this.shadowRoot.querySelectorAll("[data-dock]")) {
            button.setAttribute("aria-pressed", button.dataset.dock === this.dock ? "true" : "false");
        }
    }

    setDock(dock) {
        if (!DOCKS.includes(dock)) {
            return;
        }
        this.dock = dock;
        this.panelPos = null;
        this.panelDragged = false;
        try {
            localStorage.setItem("doska.dock", dock);
            localStorage.removeItem("doska.panel");
        } catch {
            // Private mode keeps the choice for this page only.
        }
        this.applyDock();
        if (this.shadowRoot.querySelector(".panel.open")) {
            this.anchor();
        }
    }

    showPanel(name) {
        let open = null;
        for (const panel of this.shadowRoot.querySelectorAll(".panel")) {
            const on = panel.dataset.panel === name;
            panel.classList.toggle("open", on);
            if (on) {
                open = panel;
            }
        }
        if (!open) {
            return;
        }
        open.style.visibility = "hidden";
        if (this.panelDragged && this.panelPos) {
            this.place(this.panelPos.left, this.panelPos.top);
        } else {
            this.anchor();
        }
        open.style.visibility = "";
    }

    anchor() {
        const bar = this.shadowRoot.querySelector(".bar").getBoundingClientRect();
        const panel = this.shadowRoot.querySelector(".panel.open");
        if (!panel) {
            return;
        }
        const gap = 8;
        let left = 0;
        let top = 0;
        if (this.dock === "bottom") {
            top = -(panel.offsetHeight + gap);
        } else if (this.dock === "top") {
            top = bar.height + gap;
        } else if (this.dock === "left") {
            left = bar.width + gap;
        } else {
            left = -(panel.offsetWidth + gap);
        }
        this.place(left, top);
    }

    place(left, top) {
        const panels = [...this.shadowRoot.querySelectorAll(".panel")];
        const open = this.shadowRoot.querySelector(".panel.open");
        const apply = (x, y) => {
            for (const panel of panels) {
                panel.style.left = x + "px";
                panel.style.top = y + "px";
            }
        };
        apply(left, top);
        let x = left;
        let y = top;
        if (open) {
            const rect = open.getBoundingClientRect();
            const margin = 8;
            if (rect.width > 0 && rect.height > 0) {
                if (rect.left < margin) {
                    x += margin - rect.left;
                } else if (rect.right > window.innerWidth - margin) {
                    x += window.innerWidth - margin - rect.right;
                }
                if (rect.top < margin) {
                    y += margin - rect.top;
                } else if (rect.bottom > window.innerHeight - margin) {
                    y += window.innerHeight - margin - rect.bottom;
                }
            }
        }
        if (x !== left || y !== top) {
            apply(x, y);
        }
        this.panelPos = { left: x, top: y };
    }

    setTool(next) {
        const tool = next && this.tool === next ? null : next;
        this.tool = tool;
        this.inspecting = tool === "inspect";
        this.pinnedIssue = null;
        if (!this.inspecting) {
            this.hideOverlay();
        }
        document.documentElement.style.cursor = this.inspecting ? "crosshair" : "";
        this.style.cursor = this.inspecting ? "default" : "";
        for (const name of ["inspect", "a11y", "settings"]) {
            this.shadowRoot.querySelector('[data-tool="' + name + '"]').setAttribute("aria-pressed", tool === name ? "true" : "false");
        }
        if (tool === "a11y") {
            this.runScan();
        }
        this.showPanel(tool ? PANEL_FOR_TOOL[tool] : null);
        if (tool === "inspect") {
            this.editor.focus();
        }
    }

    onShadowClick(event) {
        const raw = event.target instanceof Element ? event.target : event.target.parentElement;
        if (!raw) {
            return;
        }
        const button = raw.closest("button");
        if (!button || button.hasAttribute("data-grip") || button.dataset.chip) {
            return;
        }
        if (button.dataset.tool) {
            this.setTool(button.dataset.tool);
        } else if (button.hasAttribute("data-discard")) {
            this.discard();
        } else if (button.hasAttribute("data-copy")) {
            this.copy();
        } else if (button.hasAttribute("data-findings")) {
            this.copyFindings();
        } else if (button.dataset.dock) {
            this.setDock(button.dataset.dock);
        } else if (button.dataset.issue !== undefined) {
            this.showIssue(Number(button.dataset.issue));
        }
    }

    gripDown(event) {
        const raw = event.target instanceof Element ? event.target : event.target.parentElement;
        const grip = raw && raw.closest("[data-grip]");
        if (!grip || event.button !== 0) {
            return;
        }
        const rect = grip.closest(".panel").getBoundingClientRect();
        this.drag = { ox: event.clientX - rect.left, oy: event.clientY - rect.top };
        grip.setPointerCapture(event.pointerId);
        event.preventDefault();
    }

    gripMove(event) {
        if (!this.drag) {
            return;
        }
        const host = this.getBoundingClientRect();
        this.place(event.clientX - this.drag.ox - host.left, event.clientY - this.drag.oy - host.top);
    }

    gripUp() {
        if (!this.drag) {
            return;
        }
        this.drag = null;
        this.panelDragged = true;
        try {
            localStorage.setItem("doska.panel", JSON.stringify(this.panelPos));
        } catch {
            // Ignore storage failures.
        }
    }

    key(event) {
        this.meta = event.metaKey || event.ctrlKey;
        if (event.type === "keydown" && event.key === "Escape" && this.tool) {
            event.preventDefault();
            this.setTool(null);
        }
    }

    ignored(target) {
        if (!(target instanceof Element)) {
            return true;
        }
        return Boolean(target.closest("#doska-toolbar, ux-inspector, .sf-toolbar, #doska-highlight, #doska-badge"));
    }

    hover(event) {
        if (!this.inspecting) {
            return;
        }
        if (this.ignored(event.target)) {
            this.hideOverlay();
            return;
        }
        const el = event.target instanceof Element ? event.target : null;
        if (!el) {
            return;
        }
        const rect = el.getBoundingClientRect();
        Object.assign(this.highlight.style, {
            display: "block",
            left: rect.left + "px",
            top: rect.top + "px",
            width: rect.width + "px",
            height: rect.height + "px",
            border: "1px dashed " + (this.meta ? "#7c3aed" : "#2563eb"),
            background: this.meta ? "rgba(124, 58, 237, 0.12)" : "rgba(37, 99, 235, 0.12)",
        });
        const source = resolveSource(el);
        const label = this.meta ? (componentLabel(source.stack) || el.tagName.toLowerCase()) : el.tagName.toLowerCase();
        this.badge.textContent = label;
        this.badge.style.display = "block";
        this.badge.style.background = this.meta ? "#7c3aed" : "#2563eb";
        const top = Math.max(0, rect.top - 18);
        this.badge.style.left = Math.min(rect.right - 8, window.innerWidth - 40) + "px";
        this.badge.style.top = top + "px";
        this.badge.style.transform = "translateX(-100%)";
    }

    hideOverlay() {
        if (this.highlight) {
            this.highlight.style.display = "none";
        }
        if (this.badge) {
            this.badge.style.display = "none";
        }
    }

    pick(event) {
        if (!this.inspecting || this.ignored(event.target)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const el = event.target instanceof Element ? event.target : event.target.parentElement;
        if (!el) {
            return;
        }
        this.addChip(elementInfo(el));
        this.editor.focus();
    }

    addChip(info) {
        const chip = document.createElement("button");
        chip.type = "button";
        chip.className = "chip";
        chip.contentEditable = "false";
        chip.dataset.chip = "1";
        chip._info = info;
        const label = document.createElement("span");
        label.textContent = componentLabel(info.stack) || info.tagName;
        const remove = document.createElement("span");
        remove.textContent = "×";
        remove.setAttribute("aria-hidden", "true");
        chip.append(label, remove);
        chip.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            chip.remove();
            this.syncPlaceholder();
        });
        this.chips.push(info);
        this.editor.append(chip, document.createTextNode(" "));
        this.syncPlaceholder();
    }

    syncPlaceholder() {
        const empty = this.editor.textContent.trim() === "" && this.editor.querySelector("[data-chip]") === null;
        this.placeholder.style.display = empty ? "block" : "none";
    }

    discard() {
        this.chips = [];
        this.editor.replaceChildren();
        this.syncPlaceholder();
        if (this.copyLabel) {
            this.copyLabel.textContent = "Copy prompt";
        }
        this.setTool(null);
    }

    editorContent() {
        const chips = [];
        let text = "";
        for (const node of this.editor.childNodes) {
            if (node.nodeType === Node.TEXT_NODE) {
                text += node.textContent;
            } else if (node.dataset && node.dataset.chip) {
                text += "[selected_element](reference#" + chips.length + ")";
                chips.push(node._info);
            }
        }
        return { chips, text };
    }

    async writeClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const area = document.createElement("textarea");
            area.value = text;
            document.body.append(area);
            area.select();
            document.execCommand("copy");
            area.remove();
        }
    }

    async copy() {
        const content = this.editorContent();
        this.chips = content.chips;
        await this.writeClipboard(buildPrompt(this.config, content.chips, content.text));
        this.copyLabel.textContent = "Copied";
        window.setTimeout(() => {
            if (this.copyLabel.textContent === "Copied") {
                this.copyLabel.textContent = "Copy prompt";
            }
        }, 1600);
    }

    runScan() {
        this.issues = scanPage();
        this.pinnedIssue = null;
        const list = this.shadowRoot.querySelector("[data-issues]");
        const count = this.shadowRoot.querySelector("[data-count]");
        list.replaceChildren();
        count.textContent = this.issues.length === 0
            ? "No issues"
            : this.issues.length + (this.issues.length === 1 ? " issue" : " issues");
        if (this.issues.length === 0) {
            const empty = document.createElement("p");
            empty.className = "empty";
            empty.textContent = "No accessibility issues found on this page.";
            list.append(empty);
        } else {
            this.issues.forEach((issue, index) => {
                const button = document.createElement("button");
                button.type = "button";
                button.className = "issue";
                button.dataset.issue = String(index);
                const title = document.createElement("strong");
                title.textContent = issue.message;
                const meta = document.createElement("span");
                meta.textContent = issue.where;
                button.append(title, meta);
                list.append(button);
            });
        }
        if (this.findingsLabel) {
            this.findingsLabel.textContent = "Copy findings";
        }
    }

    showIssue(index) {
        const issue = this.issues[index];
        if (!issue || !issue.el.isConnected) {
            return;
        }
        this.pinnedIssue = issue;
        issue.el.scrollIntoView({ block: "center", inline: "nearest" });
        this.shadowRoot.querySelectorAll(".issue").forEach((button, buttonIndex) => {
            button.classList.toggle("active", buttonIndex === index);
        });
        this.paintPin();
    }

    reflowPin() {
        if (this.inspecting || !this.pinnedIssue) {
            return;
        }
        this.paintPin();
    }

    paintPin() {
        const el = this.pinnedIssue && this.pinnedIssue.el;
        if (!el || !el.isConnected) {
            this.hideOverlay();
            return;
        }
        const rect = el.getBoundingClientRect();
        Object.assign(this.highlight.style, {
            display: "block",
            left: rect.left + "px",
            top: rect.top + "px",
            width: Math.max(rect.width, 2) + "px",
            height: Math.max(rect.height, 2) + "px",
            border: "2px solid #f59e0b",
            background: "rgba(245, 158, 11, 0.18)",
        });
        if (this.badge) {
            this.badge.style.display = "none";
        }
    }

    async copyFindings() {
        const lines = ["Accessibility findings for " + location.href, ""];
        if (this.issues.length === 0) {
            lines.push("No issues found.");
        }
        this.issues.forEach((issue, index) => {
            lines.push((index + 1) + ". " + issue.message);
            lines.push("   " + issue.where);
            if (issue.selector && issue.selector !== issue.where) {
                lines.push("   " + issue.selector);
            }
        });
        await this.writeClipboard(lines.join("\n"));
        this.findingsLabel.textContent = "Copied";
        window.setTimeout(() => {
            if (this.findingsLabel.textContent === "Copied") {
                this.findingsLabel.textContent = "Copy findings";
            }
        }, 1600);
    }
}

if (!customElements.get("doska-toolbar")) {
    customElements.define("doska-toolbar", DoskaToolbar);
}

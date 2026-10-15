import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const ELEMENT_NODE = 1;
const TEXT_NODE = 3;
const COMMENT_NODE = 8;
const DOCUMENT_POSITION_PRECEDING = 2;
const DOCUMENT_POSITION_FOLLOWING = 4;

class MiniNode {
    constructor(nodeType) {
        this.nodeType = nodeType;
        this.parentNode = null;
        this.childNodes = [];
    }

    append(child) {
        child.parentNode = this;
        this.childNodes.push(child);
        return child;
    }

    compareDocumentPosition(other) {
        const order = [];
        const walk = (node) => {
            order.push(node);
            node.childNodes.forEach(walk);
        };
        walk(documentElement);
        const here = order.indexOf(this);
        const there = order.indexOf(other);
        if (there < here) {
            return DOCUMENT_POSITION_PRECEDING;
        }
        if (there > here) {
            return DOCUMENT_POSITION_FOLLOWING;
        }
        return 0;
    }
}

class MiniElement extends MiniNode {
    constructor(tagName) {
        super(ELEMENT_NODE);
        this.tagName = tagName.toUpperCase();
        this.attrs = {};
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
    }
}

class MiniComment extends MiniNode {
    constructor(value) {
        super(COMMENT_NODE);
        this.nodeValue = value;
    }
}

let documentElement = null;

function parse(html) {
    const root = new MiniElement("html");
    const stack = [root];
    const pattern = /<!--([\s\S]*?)-->|<\/([a-zA-Z][a-zA-Z0-9:-]*)\s*>|<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^<>]*?)?\s*(\/?)>/g;
    let last = 0;
    let match;
    while ((match = pattern.exec(html))) {
        if (match.index > last) {
            stack.at(-1).append(Object.assign(new MiniNode(TEXT_NODE), { textContent: html.slice(last, match.index) }));
        }
        if (match[1] !== undefined) {
            stack.at(-1).append(new MiniComment(match[1]));
        } else if (match[2]) {
            const name = match[2].toLowerCase();
            while (stack.length > 1 && stack.at(-1).tagName.toLowerCase() !== name) {
                stack.pop();
            }
            if (stack.length > 1) {
                stack.pop();
            }
        } else {
            const el = new MiniElement(match[3]);
            const attrs = match[4] || "";
            for (const attr of attrs.matchAll(/([^\s=]+)="([^"]*)"/g)) {
                el.attrs[attr[1]] = attr[2];
            }
            stack.at(-1).append(el);
            if (!match[5]) {
                stack.push(el);
            }
        }
        last = pattern.lastIndex;
    }
    documentElement = root;
    return root;
}

function elementByTag(root, tag) {
    const want = tag.toUpperCase();
    const walk = (node) => {
        if (node.tagName === want) {
            return node;
        }
        for (const child of node.childNodes) {
            const found = walk(child);
            if (found) {
                return found;
            }
        }
        return null;
    };
    return walk(root);
}

const sandbox = {
    Node: { ELEMENT_NODE, TEXT_NODE, COMMENT_NODE, DOCUMENT_POSITION_PRECEDING },
    NodeFilter: { SHOW_COMMENT: 128 },
    HTMLElement: class HTMLElement {},
    customElements: { get() { return undefined; }, define() {} },
    window: {},
    location: { href: "https://drehscheibe.test/driving/trip" },
    document: {
        createNodeIterator(root) {
            const comments = [];
            const walk = (node) => {
                if (node.nodeType === COMMENT_NODE) {
                    comments.push(node);
                }
                node.childNodes.forEach(walk);
            };
            walk(root);
            let index = 0;
            return { nextNode: () => comments[index++] ?? null };
        },
        get documentElement() {
            return documentElement;
        },
    },
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(
    readFileSync(join(dirname(fileURLToPath(import.meta.url)), "../../assets/toolbar.js"), "utf8"),
    sandbox,
);

const fixture = `
<body>
<!-- <controller> src/Controller/Driving/TripController.php:84 (app) | App\\Controller\\Driving\\TripController::index route:trip-list -->
<!-- @caller templates/driving/trip/list.html.twig:142 (app) -->
<!-- <Button> templates/components/Button.html.twig:18 (app) | src/Twig/Components/Button.php:12 -->
<button data-doska-loc="templates/components/Button.html.twig:18">Save</button>
<!-- </Button> -->
<!-- @caller templates/driving/trip/list.html.twig:200 (app) -->
<!-- <Badge> templates/components/Badge.html.twig:1 (app) -->
<span data-doska-loc="templates/driving/trip/list.html.twig:210">Later</span>
<!-- </Badge> -->
<p>Fallback</p>
<!-- </controller> -->
</body>
`;

test("data-doska-loc wins and the caller belongs to the next frame", () => {
    const root = parse(fixture);
    const button = elementByTag(root, "button");
    const source = sandbox.resolveSource(button);

    assert.equal(source.file, "templates/components/Button.html.twig");
    assert.equal(source.line, 18);
    assert.equal(source.stack.map((frame) => frame.name).join("|"), "controller|Button");
    assert.equal(source.stack[1].caller.file, "templates/driving/trip/list.html.twig");
    assert.equal(source.stack[1].caller.line, 142);
    assert.equal(source.stack[1].extra, "src/Twig/Components/Button.php:12");
});

test("slot content keeps the caller file and drops closed frames", () => {
    const root = parse(fixture);
    const span = elementByTag(root, "span");
    const source = sandbox.resolveSource(span);

    assert.equal(source.file, "templates/driving/trip/list.html.twig");
    assert.equal(source.line, 210);
    assert.equal(source.stack.map((frame) => frame.name).join("|"), "controller|Badge");
    assert.equal(source.stack[1].caller.line, 200);
    assert.equal(source.stack[0].caller, null);
});

test("a tag without data-doska-loc falls back to the open frame", () => {
    const root = parse(fixture);
    const paragraph = elementByTag(root, "p");
    const source = sandbox.resolveSource(paragraph);

    assert.equal(source.file, "src/Controller/Driving/TripController.php");
    assert.equal(source.line, 84);
    assert.equal(source.stack.map((frame) => frame.name).join("|"), "controller");
});

test("the copied prompt keeps chip markers once", () => {
    const root = parse(fixture);
    const button = elementByTag(root, "button");
    const source = sandbox.resolveSource(button);
    const prompt = sandbox.buildPrompt(
        {
            controller: "App\\Controller\\Driving\\TripController::index",
            source: "src/Controller/Driving/TripController.php:84",
            route: "trip-list",
        },
        [{
            tagName: "button",
            selector: "button",
            textContent: "Save",
            selectedText: "",
            file: source.file,
            line: source.line,
            stack: source.stack,
            ux: "",
        }],
        "[selected_element](reference#0) Make this shorter",
    );

    assert.match(prompt, /<user_prompt>\n\[selected_element\]\(reference#0\) Make this shorter\n<\/user_prompt>/);
    assert.equal(prompt.match(/\[selected_element\]/g).length, 1);
    assert.match(prompt, /route: trip-list/);
    assert.match(prompt, /templates\/components\/Button\.html\.twig:18/);
    assert.match(prompt, /templates\/driving\/trip\/list\.html\.twig:142: Button/);
    assert.match(prompt, /entrypoint: App\\Controller\\Driving\\TripController::index/);
    assert.match(prompt, /Button class: src\/Twig\/Components\/Button\.php:12/);
    assert.doesNotMatch(prompt, /<button>/);
});

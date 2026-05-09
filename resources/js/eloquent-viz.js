import cytoscape from 'cytoscape';
import coseBilkent from 'cytoscape-cose-bilkent';

cytoscape.use(coseBilkent);

const GRAPH_URL = window.__ELOQUENT_VIZ__?.graphUrl ?? '/eloquent-viz/graph';
const BOOT_AVAILABLE_MODELS = Array.isArray(window.__ELOQUENT_VIZ__?.availableModels)
    ? window.__ELOQUENT_VIZ__.availableModels
    : [];
const BOOT_SELECTED_MODEL = typeof window.__ELOQUENT_VIZ__?.selectedModel === 'string'
    ? window.__ELOQUENT_VIZ__.selectedModel
    : '';

/** @param {unknown} row */
function modelOptionValue(row) {
    if (row && typeof row === 'object' && 'id' in row) {
        return String(/** @type {{ id: string }} */ (row).id);
    }

    return String(row ?? '');
}

/** @param {unknown} row */
function modelOptionLabel(row) {
    if (row && typeof row === 'object' && 'label' in row) {
        return String(/** @type {{ label: string }} */ (row).label);
    }

    return modelOptionValue(row);
}

const PALETTE = {
    hasMany: '#38bdf8',
    belongsTo: '#a78bfa',
    hasOne: '#34d399',
    morphMany: '#fb7185',
    morphTo: '#fbbf24',
    default: '#94a3b8',
};

function colorForRelType(type) {
    if (!type) {
        return PALETTE.default;
    }
    const key = Object.keys(PALETTE).find((k) => type === k || type.startsWith(k));
    return key ? PALETTE[key] : PALETTE.default;
}

const EDGE_CP_STEP = 52;
const EDGE_LABEL_STAGGER = 15;

/**
 * Build multi-line edge label from optional `smart` metadata (FKs, pivot, morph).
 */
function formatSmartLabel(type, smart, count) {
    const n = Math.max(1, Math.floor(Number(count)) || 1);
    // Keep ×n on the same line as the relation type: with `text-rotation: autorotate`,
    // Cytoscape often drops or clips additional lines on edge labels.
    const head = n > 1 ? `${type} ×${n}` : type;
    if (!smart || typeof smart !== 'object') {
        return head;
    }

    const lines = [head];

    if (smart.pivotTable) {
        let p = `pivot: ${smart.pivotTable}`;
        if (smart.foreignPivotKey && smart.relatedPivotKey) {
            p += `\n  ${smart.foreignPivotKey} · ${smart.relatedPivotKey}`;
        }
        lines.push(p);
    }

    if (smart.morphTypeColumn) {
        let m = `morph: ${smart.morphTypeColumn}`;
        if (smart.morphClass) {
            m += ` = ${smart.morphClass}`;
        }
        lines.push(m);
    }

    if (smart.throughModel) {
        let t = `through: ${smart.throughModel}`;
        const bits = [];
        if (smart.throughTable) {
            bits.push(smart.throughTable);
        }
        if (smart.firstKey && smart.foreignKey) {
            bits.push(`${smart.firstKey}→…→${smart.foreignKey}`);
        }
        if (bits.length) {
            t += `\n  ${bits.join(' · ')}`;
        }
        lines.push(t);
    } else if (smart.foreignKey) {
        let f = `fk: ${smart.foreignKey}`;
        if (smart.ownerKey) {
            f += ` → ${smart.ownerKey}`;
        } else if (smart.localKey) {
            f += ` (parent ${smart.localKey})`;
        }
        lines.push(f);
    }

    return lines.join('\n');
}

/** @param {Record<string, unknown>} e */
function edgeMultiplicityWeight(e) {
    const m = Number(e.multiplicity);
    if (Number.isFinite(m) && m > 1) {
        return m;
    }

    return 1;
}

/**
 * Merge identical (from, to, type) edges into one with "type ×n", then assign
 * unbundled-bezier offsets and staggered label margins per directed endpoint pair.
 * Honors API {@code multiplicity} when the scanner has already deduped parallel methods.
 */
function mergeAndAnnotateEdges(graph) {
    const raw = (graph.edges ?? []).filter((e) => e.to != null && e.type);

    /** @type {Map<string, { from: string, to: string, type: string, count: number, apiMax: number, smart?: Record<string, string> }>} */
    const mergeMap = new Map();
    for (const e of raw) {
        const k = `${e.from}\0${e.to}\0${e.type}`;
        const apiM = Number(e.multiplicity);
        const apiFloor = Number.isFinite(apiM) && apiM >= 1 ? Math.floor(apiM) : 1;

        if (!mergeMap.has(k)) {
            mergeMap.set(k, {
                from: e.from,
                to: e.to,
                type: e.type,
                count: 0,
                apiMax: apiFloor,
                smart: e.smart && typeof e.smart === 'object' ? { ...e.smart } : undefined,
            });
        } else {
            const row = mergeMap.get(k);
            row.apiMax = Math.max(row.apiMax, apiFloor);
        }

        mergeMap.get(k).count += edgeMultiplicityWeight(e);
    }

    /** @type {Array<{ from: string, to: string, type: string, count: number, apiMax: number, displayCount: number, smart?: Record<string, string>, cpDistance?: number, cpWeight?: number, textMarginY?: number }>} */
    const merged = [...mergeMap.values()].map((row) => ({
        ...row,
        displayCount: Math.max(row.count, row.apiMax),
    }));

    const byPair = new Map();
    for (const e of merged) {
        const pair = `${e.from}\0${e.to}`;
        if (!byPair.has(pair)) {
            byPair.set(pair, []);
        }
        byPair.get(pair).push(e);
    }

    for (const list of byPair.values()) {
        list.sort((a, b) => a.type.localeCompare(b.type));
        const n = list.length;
        list.forEach((e, i) => {
            e.cpDistance = (i - (n - 1) / 2) * EDGE_CP_STEP;
            e.cpWeight = 0.5;
            e.textMarginY = -8 - i * EDGE_LABEL_STAGGER;
        });
    }

    return merged.map((e, i) => ({
        ...e,
        id: `e-${i}`,
        label: formatSmartLabel(e.type, e.smart, e.displayCount),
    }));
}

/**
 * @param {unknown[]} nodes graphVersion 2: `{ id, label, fqcn, file? }[]`; legacy: string ids
 */
function buildElements(nodes, annotatedEdges) {
    const elements = [];

    for (const raw of nodes ?? []) {
        if (typeof raw === 'string') {
            elements.push({
                group: 'nodes',
                data: { id: raw, label: raw, fqcn: raw },
            });

            continue;
        }

        if (raw && typeof raw === 'object' && 'id' in raw) {
            const n = /** @type {{ id: string, label?: string, fqcn?: string, file?: string }} */ (raw);
            elements.push({
                group: 'nodes',
                data: {
                    id: String(n.id),
                    label: String(n.label ?? n.id),
                    fqcn: String(n.fqcn ?? n.id),
                    file: n.file != null ? String(n.file) : '',
                },
            });
        }
    }

    for (const edge of annotatedEdges) {
        const smartObj = edge.smart && typeof edge.smart === 'object' ? edge.smart : null;
        elements.push({
            group: 'edges',
            data: {
                id: String(edge.id),
                source: String(edge.from ?? ''),
                target: String(edge.to ?? ''),
                relType: String(edge.type ?? ''),
                label: String(edge.label ?? ''),
                multiplicity: Number(edge.displayCount) || 1,
                smartJson: smartObj ? JSON.stringify(smartObj) : '',
                cpDistance: Number(edge.cpDistance) || 0,
                cpWeight: Number(edge.cpWeight) || 0.5,
                textMarginY: Number(edge.textMarginY) || 0,
                lineColor: colorForRelType(edge.type),
            },
        });
    }

    return elements;
}

function collectRelTypes(graph) {
    const types = new Set();
    for (const edge of graph.edges ?? []) {
        if (edge.to != null && edge.type) {
            types.add(edge.type);
        }
    }
    return [...types].sort();
}

function stylesheet() {
    return [
        {
            selector: 'node',
            style: {
                label: 'data(label)',
                'text-valign': 'center',
                'text-halign': 'center',
                'text-wrap': 'wrap',
                'text-max-width': '10em',
                'font-size': '13px',
                'font-weight': '600',
                color: '#0f172a',
                'background-color': '#e2e8f0',
                'border-width': 2,
                'border-color': '#64748b',
                width: 120,
                height: 44,
                padding: '10px',
                shape: 'roundrectangle',
            },
        },
        {
            selector: 'node:selected',
            style: {
                'border-color': '#38bdf8',
                'border-width': 3,
                'background-color': '#f1f5f9',
            },
        },
        {
            selector: 'edge',
            style: {
                width: 2,
                'curve-style': 'unbundled-bezier',
                'control-point-distance': 'data(cpDistance)',
                'control-point-weight': 'data(cpWeight)',
                'target-arrow-shape': 'triangle',
                'target-arrow-color': 'data(lineColor)',
                'line-color': 'data(lineColor)',
                'arrow-scale': 1.1,
                label: '',
                'font-size': '12px',
                'font-weight': '600',
                color: '#f8fafc',
                'text-rotation': 'autorotate',
                'text-margin-y': 'data(textMarginY)',
                'line-height': 1.35,
                'text-wrap': 'wrap',
                'text-max-width': '200px',
                'text-halign': 'center',
                'text-valign': 'center',
                'text-background-color': '#0f172a',
                'text-background-opacity': 0.96,
                'text-background-padding': '8px',
                'text-background-shape': 'roundrectangle',
                'text-border-width': 1,
                'text-border-color': '#334155',
                'text-border-opacity': 1,
                'text-outline-width': 2,
                'text-outline-color': '#020617',
                'min-zoomed-font-size': 9,
                opacity: 0.72,
            },
        },
        {
            selector: 'edge:selected',
            style: {
                width: 3,
                opacity: 1,
                label: 'data(label)',
                'font-size': '13px',
                'font-weight': '600',
                'text-max-width': '260px',
                'text-background-padding': '10px',
                'line-height': 1.4,
            },
        },
    ];
}

function applyLineColors(cy) {
    cy.edges().forEach((edge) => {
        const c = colorForRelType(edge.data('relType'));
        edge.data('lineColor', c);
    });
}

/** Edges should be selectable for details, but never grabbable (avoids “drag” on line click). */
function setEdgeGrabPolicy(cy) {
    cy.edges().ungrabify();
    cy.nodes().grabify();
}

function applyVisibility(cy, selectedTypes, nodeQuery) {
    const q = nodeQuery.trim().toLowerCase();

    cy.batch(() => {
        cy.nodes().forEach((node) => {
            const id = node.id().toLowerCase();
            const label = String(node.data('label') ?? '').toLowerCase();
            const fqcn = String(node.data('fqcn') ?? '').toLowerCase();
            const file = String(node.data('file') ?? '').toLowerCase();
            const match = !q || id.includes(q) || label.includes(q) || fqcn.includes(q) || file.includes(q);
            node.style('display', match ? 'element' : 'none');
        });

        cy.edges().forEach((edge) => {
            const type = edge.data('relType');
            const typeOk = selectedTypes.has(type);
            const src = edge.source();
            const tgt = edge.target();
            const endpointsOk = src.visible() && tgt.visible();
            const show = typeOk && endpointsOk;
            edge.style('display', show ? 'element' : 'none');
        });
    });
}

function mountFilters(container, types, onChange) {
    container.replaceChildren();

    const wrap = document.createElement('div');
    wrap.className = 'ev-stack';
    container.appendChild(wrap);

    const selected = new Set(types);

    types.forEach((type) => {
        const row = document.createElement('label');
        row.className = 'ev-filter-row';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = true;
        input.className = 'ev-checkbox';
        input.addEventListener('change', () => {
            if (input.checked) {
                selected.add(type);
            } else {
                selected.delete(type);
            }
            onChange(selected);
        });

        const swatch = document.createElement('span');
        swatch.className = 'ev-swatch';
        swatch.style.backgroundColor = colorForRelType(type);

        const text = document.createElement('span');
        text.textContent = type;

        row.appendChild(input);
        row.appendChild(swatch);
        row.appendChild(text);
        wrap.appendChild(row);
    });

    const actions = document.createElement('div');
    actions.className = 'ev-btn-row';

    const all = document.createElement('button');
    all.type = 'button';
    all.className = 'ev-btn ev-btn-secondary';
    all.textContent = 'All types';
    all.addEventListener('click', () => {
        types.forEach((t) => selected.add(t));
        wrap.querySelectorAll('input[type=checkbox]').forEach((cb) => {
            cb.checked = true;
        });
        onChange(selected);
    });

    const none = document.createElement('button');
    none.type = 'button';
    none.className = 'ev-btn ev-btn-secondary';
    none.textContent = 'None';
    none.addEventListener('click', () => {
        selected.clear();
        wrap.querySelectorAll('input[type=checkbox]').forEach((cb) => {
            cb.checked = false;
        });
        onChange(selected);
    });

    actions.appendChild(all);
    actions.appendChild(none);
    container.appendChild(actions);

    return selected;
}

async function main() {
    const container = document.getElementById('cy');
    const statusEl = document.getElementById('graph-status');
    const filterHost = document.getElementById('rel-type-filter-controls');
    const nodeFilterInput = document.getElementById('node-filter');
    const resetZoomBtn = document.getElementById('reset-zoom');
    const relayoutBtn = document.getElementById('relayout');
    const selectedModelInput = document.getElementById('selected-model');
    const selectionDetails = document.getElementById('selection-details');

    if (!container || !statusEl || !filterHost) {
        return;
    }

    statusEl.textContent = 'Loading graph…';

    let cy = cytoscape({
        container,
        elements: [],
        style: stylesheet(),
        minZoom: 0.15,
        maxZoom: 2.75,
        boxSelectionEnabled: true,
        autounselectify: false,
    });

    applyLineColors(cy);

    let selectedTypes = new Set();
    let currentGraph = { nodes: [], edges: [] };
    const runFilters = () => applyVisibility(cy, selectedTypes, nodeFilterInput?.value ?? '');

    const applyTypeFilters = (types) => mountFilters(filterHost, types, (set) => {
        selectedTypes = set;
        runFilters();
    });

    nodeFilterInput?.addEventListener('input', runFilters);
    const runLayout = () => {
        const onLayoutStop = () => setEdgeGrabPolicy(cy);
        const rootModel = selectedModelInput?.value ?? '';
        if (rootModel !== '') {
            const layout = cy.layout({
                name: 'breadthfirst',
                directed: true,
                fit: true,
                circle: false,
                avoidOverlap: true,
                spacingFactor: 1.15,
                roots: [rootModel],
                padding: 56,
                animate: true,
                animationDuration: 450,
            });
            layout.one('layoutstop', onLayoutStop);
            layout.run();

            return;
        }

        const layout = cy.layout({
            name: 'cose-bilkent',
            quality: 'default',
            randomize: true,
            animate: true,
            animationDuration: 600,
            fit: true,
            padding: 52,
            nodeRepulsion: 9500,
            idealEdgeLength: 150,
            edgeElasticity: 0.42,
        });
        layout.one('layoutstop', onLayoutStop);
        layout.run();
    };

    const jumpToModel = (model) => {
        if (!(selectedModelInput instanceof HTMLSelectElement) || !model) {
            return;
        }

        selectedModelInput.value = model;
        loadGraph(model);
    };

    const nodeLabelById = (id) => {
        const n = (currentGraph.nodes ?? []).find((x) => x && typeof x === 'object' && x.id === id);
        if (n && typeof n === 'object' && 'label' in n) {
            return String(/** @type {{ label: string }} */ (n).label);
        }

        return id;
    };

    const computeDirectRelationStats = (modelId) => {
        const edges = currentGraph.edges ?? [];

        const outgoingIds = [...new Set(
            edges
                .filter((e) => e.from === modelId && typeof e.to === 'string' && e.to !== '')
                .map((e) => /** @type {string} */ (e.to))
        )].sort((a, b) => a.localeCompare(b));

        const incomingIds = [...new Set(
            edges
                .filter((e) => e.to === modelId && typeof e.from === 'string' && e.from !== '')
                .map((e) => /** @type {string} */ (e.from))
        )].sort((a, b) => a.localeCompare(b));

        const allConnected = [...new Set([...outgoingIds, ...incomingIds])]
            .sort((a, b) => a.localeCompare(b));

        return {
            outgoingModels: outgoingIds.map((id) => ({ id, label: nodeLabelById(id) })),
            incomingModels: incomingIds.map((id) => ({ id, label: nodeLabelById(id) })),
            allConnected: allConnected.map((id) => ({ id, label: nodeLabelById(id) })),
        };
    };

    const renderSelection = (target) => {
        if (!selectionDetails) {
            return;
        }

        const esc = (s) => String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');

        if (!target) {
            selectionDetails.innerHTML = '<p class="ev-selection-empty">Click a model or relation to inspect details.</p>';
            return;
        }

        if (target.isNode?.()) {
            const modelId = target.id();
            const fqcn = String(target.data('fqcn') ?? modelId);
            const file = String(target.data('file') ?? '');
            const shortLabel = String(target.data('label') ?? modelId);
            const stats = computeDirectRelationStats(modelId);

            selectionDetails.innerHTML = `
                <dl class="ev-selection-grid">
                    <dt>Type</dt><dd>Model</dd>
                    <dt>Label</dt><dd>${esc(shortLabel)}</dd>
                    <dt>FQCN</dt><dd><code class="ev-code">${esc(fqcn)}</code></dd>
                    ${file ? `<dt>File</dt><dd><code class="ev-code">${esc(file)}</code></dd>` : ''}
                    <dt>References other models</dt><dd>${stats.outgoingModels.length}</dd>
                    <dt>Referenced by other models</dt><dd>${stats.incomingModels.length}</dd>
                </dl>
                ${stats.allConnected.length > 0
                    ? `<div class="ev-selection-actions">
                        <select id="selection-jump-model" class="ev-input" aria-label="Jump to related model">
                            <option value="">Navigate to related model…</option>
                            ${stats.allConnected.map((row) => `<option value="${esc(row.id)}">${esc(row.label)}</option>`).join('')}
                        </select>
                        <button type="button" id="selection-jump-button" class="ev-btn ev-btn-primary">Go</button>
                    </div>`
                    : '<p class="ev-selection-empty" style="margin-top:0.65rem;">No directly related models.</p>'
                }
            `;

            const jumpSelect = selectionDetails.querySelector('#selection-jump-model');
            const jumpButton = selectionDetails.querySelector('#selection-jump-button');

            if (jumpSelect && jumpButton) {
                jumpButton.addEventListener('click', () => {
                    const nextModel = jumpSelect.value;
                    jumpToModel(nextModel);
                });
                jumpSelect.addEventListener('change', () => {
                    if (jumpSelect.value !== '') {
                        jumpToModel(jumpSelect.value);
                    }
                });
            }
            return;
        }

        if (target.isEdge?.()) {
            const data = target.data();
            const srcId = String(data.source ?? '');
            const tgtId = String(data.target ?? '');
            const rows = [
                ['Type', 'Relationship', false],
                ['From (id)', srcId, true],
                ['From (label)', nodeLabelById(srcId), false],
                ['To (id)', tgtId, true],
                ['To (label)', nodeLabelById(tgtId), false],
                ['Relation', String(data.relType ?? ''), false],
            ];

            if (Number(data.multiplicity) > 1) {
                rows.push(['Multiplicity', String(data.multiplicity), false]);
            }

            let smart = /** @type {Record<string, string>} */ ({});
            const sj = data.smartJson;
            if (typeof sj === 'string' && sj !== '') {
                try {
                    const parsed = JSON.parse(sj);
                    if (parsed && typeof parsed === 'object') {
                        smart = /** @type {Record<string, string>} */ (parsed);
                    }
                } catch {
                    smart = {};
                }
            } else if (data.smart && typeof data.smart === 'object') {
                smart = /** @type {Record<string, string>} */ (data.smart);
            }

            Object.entries(smart).forEach(([key, value]) => {
                const val = String(value);
                const useCode = val.length > 36 || val.includes('\\') || val.includes('/') || val.includes('_');
                rows.push([key, val, useCode]);
            });

            const html = rows
                .map(([k, v, forceCode]) => {
                    const ks = esc(k);
                    const vs = esc(v);
                    const useCode = forceCode || v.length > 48 || v.includes('\\') || v.includes('/') || v.includes('::');
                    const dd = useCode ? `<code class="ev-code">${vs}</code>` : vs;

                    return `<dt>${ks}</dt><dd>${dd}</dd>`;
                })
                .join('');

            selectionDetails.innerHTML = `<dl class="ev-selection-grid">${html}</dl>`;
        }
    };

    const populateModelSelect = (availableModels, selectedModel) => {
        if (!(selectedModelInput instanceof HTMLSelectElement)) {
            return;
        }

        const existing = new Set(
            [...selectedModelInput.options].map((option) => option.value)
        );
        for (const row of availableModels) {
            const val = modelOptionValue(row);
            if (existing.has(val)) {
                continue;
            }

            const option = document.createElement('option');
            option.value = val;
            option.textContent = modelOptionLabel(row);
            selectedModelInput.appendChild(option);
        }

        selectedModelInput.value = selectedModel ?? '';
    };

    const renderDiagnostics = (graph) => {
        const host = document.getElementById('scan-diagnostics');
        const list = document.getElementById('scan-diagnostics-list');
        if (!host || !list) {
            return;
        }

        const warnings = Array.isArray(graph.warnings) ? graph.warnings : [];
        const skipped = Array.isArray(graph.skippedRelations) ? graph.skippedRelations : [];

        list.replaceChildren();

        const appendWarning = (w) => {
            if (!w || typeof w !== 'object' || !('message' in w)) {
                return;
            }

            const li = document.createElement('li');
            li.className = 'ev-diagnostics-item';

            const code = w.code;
            if (typeof code === 'string' && code !== '') {
                const meta = document.createElement('div');
                meta.className = 'ev-diagnostics-meta';
                meta.textContent = code;
                li.appendChild(meta);
            }

            const msg = document.createElement('p');
            msg.style.margin = '0';
            msg.style.lineHeight = '1.45';
            msg.textContent = String(w.message);
            li.appendChild(msg);

            const candidates = w.candidates;
            const hasCandidates = Array.isArray(candidates) && candidates.length > 0;
            if (hasCandidates) {
                const sub = document.createElement('ul');
                sub.className = 'ev-diagnostics-candidates';
                for (const c of candidates) {
                    const cli = document.createElement('li');
                    const pre = document.createElement('span');
                    pre.textContent = String(c);
                    cli.appendChild(pre);
                    sub.appendChild(cli);
                }
                li.appendChild(sub);
            }

            const target = w.target;
            if (typeof target === 'string' && target !== '' && !hasCandidates) {
                const block = document.createElement('code');
                block.className = 'ev-diagnostics-code';
                block.textContent = target;
                li.appendChild(block);
            }

            list.appendChild(li);
        };

        for (const w of warnings) {
            appendWarning(w);
        }

        for (const s of skipped) {
            if (!s || typeof s !== 'object' || !('model' in s) || !('method' in s)) {
                continue;
            }

            const li = document.createElement('li');
            li.className = 'ev-diagnostics-item';

            const meta = document.createElement('div');
            meta.className = 'ev-diagnostics-meta';
            meta.textContent = 'Skipped relation';
            li.appendChild(meta);

            const code = document.createElement('code');
            code.className = 'ev-diagnostics-code';
            code.textContent = `${String(s.model)}::${String(s.method)}()`;
            li.appendChild(code);

            if ('reason' in s && String(s.reason) !== '') {
                const r = document.createElement('p');
                r.style.margin = '0.35rem 0 0 0';
                r.style.fontSize = '0.75rem';
                r.style.color = '#fde68a';
                r.style.lineHeight = '1.4';
                r.textContent = String(s.reason);
                li.appendChild(r);
            }

            list.appendChild(li);
        }

        if (list.children.length === 0) {
            host.classList.remove('is-visible');

            return;
        }

        host.classList.add('is-visible');
    };

    const fetchGraph = async (selectedModel) => {
        const url = new URL(GRAPH_URL, window.location.origin);
        if (selectedModel) {
            url.searchParams.set('model', selectedModel);
        }

        const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        return res.json();
    };

    const loadGraph = async (selectedModel) => {
        statusEl.textContent = 'Loading graph…';

        let graph;
        try {
            graph = await fetchGraph(selectedModel);
        } catch (err) {
            statusEl.textContent = `Failed to load graph: ${err.message ?? err}`;
            return;
        }

        const annotatedEdges = mergeAndAnnotateEdges(graph);
        const elements = buildElements(graph.nodes, annotatedEdges);
        const relTypes = collectRelTypes(graph);
        currentGraph = graph;

        populateModelSelect(graph.availableModels ?? BOOT_AVAILABLE_MODELS, graph.selectedModel ?? selectedModel ?? '');

        cy.elements().remove();
        cy.add(elements);
        applyLineColors(cy);
        setEdgeGrabPolicy(cy);
        cy.$(':selected').unselect();
        renderSelection(null);

        selectedTypes = applyTypeFilters(relTypes);
        runFilters();

        renderDiagnostics(graph);

        if (elements.length === 0) {
            statusEl.textContent = 'No models or edges found.';
            return;
        }

        const gv = graph.graphVersion != null ? `v${graph.graphVersion} · ` : '';
        statusEl.textContent = `${gv}${graph.nodes?.length ?? 0} models · ${relTypes.length} relationship kinds · ${annotatedEdges.length} edges shown`;
        runLayout();
    };

    selectedModelInput?.addEventListener('change', () => {
        loadGraph(selectedModelInput.value);
    });

    cy.on('select', 'node, edge', (event) => {
        renderSelection(event.target);
    });
    cy.on('unselect', 'node, edge', () => {
        if (cy.$(':selected').length === 0) {
            renderSelection(null);
        }
    });

    populateModelSelect(BOOT_AVAILABLE_MODELS, BOOT_SELECTED_MODEL);
    await loadGraph(BOOT_SELECTED_MODEL);

    resetZoomBtn?.addEventListener('click', () => {
        cy.animate({
            fit: { eles: cy.elements(), padding: 48 },
            duration: 320,
            easing: 'ease-out-cubic',
        });
    });

    relayoutBtn?.addEventListener('click', () => {
        runLayout();
    });

    const ro = new ResizeObserver(() => {
        cy.resize();
    });
    ro.observe(container);

    window.addEventListener('beforeunload', () => ro.disconnect());
}

main();

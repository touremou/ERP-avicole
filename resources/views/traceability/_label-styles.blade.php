<style>
    /* Disposition AUTOMATIQUE : étiquettes en dimensions physiques (mm) qui se
       répartissent en grille et paginent selon le format @page choisi. Ces
       règles priment volontairement sur les tailles px des gabarits. */
    .label-sheet { display: flex; flex-wrap: wrap; gap: var(--label-gap, 4mm); align-content: flex-start; }
    .label-sheet .label { width: var(--label-w, 90mm); box-sizing: border-box; margin: 0 !important; break-inside: avoid; page-break-inside: avoid; overflow: hidden; }
    .label-sheet.has-fixed-h .label { height: var(--label-h); }
    .label-sheet .qr { width: 26mm !important; height: 26mm !important; }

    .actions { max-width: 210mm; margin: 18px auto 0; display: flex; gap: 12px; align-items: center; justify-content: center; flex-wrap: wrap; }
    .actions .cfg { display: inline-flex; gap: 10px; align-items: center; background: #fff; padding: 8px 12px; border-radius: 10px; border: 1px solid #cbd5e1; }
    .actions label { font-size: 11px; font-weight: 700; color: #475569; display: inline-flex; gap: 6px; align-items: center; }
    .actions input, .actions select { font: inherit; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 6px; }
    .actions input[type=number] { width: 60px; }
    .actions button { background: #0f172a; color: #fff; border: 0; padding: 10px 22px; border-radius: 10px; font-weight: 800; font-size: 13px; cursor: pointer; }
    .actions button.ghost { background: #e2e8f0; color: #0f172a; padding: 8px 14px; }
    .actions .back { color: #64748b; font-weight: 700; font-size: 12px; text-decoration: none; }
    .label .head { display: flex; gap: 16px; align-items: center; }
    .label .barcode { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #cbd5e1; text-align: center; }
    .label .barcode svg { max-width: 100%; height: auto; }
    .printed-at { max-width: 210mm; margin: 10px auto 0; text-align: center; font-size: 9px; color: #94a3b8; }

    @media screen { .label-sheet { max-width: 210mm; margin: 0 auto; } }
    @media print {
        body { background: #fff !important; padding: 0 !important; }
        .actions, .printed-at { display: none !important; }
        .label-sheet { max-width: none; gap: 4mm; }
    }
</style>

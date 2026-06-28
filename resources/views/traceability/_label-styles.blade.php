<style>
    /* Grille d'impression (nombre d'étiquettes par ligne = --cols). */
    .label-sheet { display: grid; grid-template-columns: repeat(var(--cols, 2), minmax(0, 1fr)); gap: 12px; max-width: 880px; margin: 0 auto; align-items: start; justify-items: center; }
    .label-sheet .label { margin: 0; width: 100%; max-width: 380px; }
    .actions { max-width: 880px; margin: 18px auto 0; display: flex; gap: 12px; align-items: center; justify-content: center; flex-wrap: wrap; }
    .actions .cfg { display: inline-flex; gap: 10px; align-items: center; background: #fff; padding: 8px 12px; border-radius: 10px; border: 1px solid #cbd5e1; }
    .actions label { font-size: 11px; font-weight: 700; color: #475569; display: inline-flex; gap: 6px; align-items: center; }
    .actions input, .actions select { font: inherit; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 6px; }
    .actions input[type=number] { width: 60px; }
    .actions button { background: #0f172a; color: #fff; border: 0; padding: 10px 22px; border-radius: 10px; font-weight: 800; font-size: 13px; cursor: pointer; }
    .actions button.ghost { background: #e2e8f0; color: #0f172a; padding: 8px 14px; }
    .actions .back { color: #64748b; font-weight: 700; font-size: 12px; text-decoration: none; }
    .printed-at { max-width: 880px; margin: 10px auto 0; text-align: center; font-size: 9px; color: #94a3b8; }
    @media print { .label-sheet { max-width: none; } .printed-at { display: none; } }
</style>

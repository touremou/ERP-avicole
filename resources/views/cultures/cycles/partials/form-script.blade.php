{{--
    LOGIQUE PARTAGÉE des formulaires de cycle de culture (création ET édition).

    Elle vivait en DEUX COPIES, une par vue. La conséquence était prévisible : le
    correctif du recalcul n'existait que dans l'une, et l'édition gardait le
    défaut. C'est la même erreur que les formulaires employé — une règle, deux
    endroits, et la divergence ne se voit qu'à l'usage.

    La fonction reçoit une CONFIGURATION plutôt qu'une liste d'arguments
    positionnels, parce que les deux écrans ne fournissent pas la même chose :
    la création connaît toutes les parcelles (surface disponible qui change avec
    le choix), l'édition n'en connaît qu'une (surface maximale figée).

      catalogue   espèces encodées (nom, durées, rendement, matériel de plantation)
      plotData    { id: { remaining_ha } } — création seulement
      maxAreaHa   surface maximale imposée — édition seulement
      initial     valeurs de départ des champs
--}}
<script>
    function cropCycleForm(config) {
        const initial = config.initial || {};

        return {
            catalogue: config.catalogue || [],
            plotData: config.plotData || {},
            maxAreaHa: config.maxAreaHa === undefined ? null : config.maxAreaHa,

            cropName: initial.cropName || '',
            variety: initial.variety || '',
            areaHa: initial.areaHa || '',
            plantingDate: initial.plantingDate || '',
            expectedHarvest: initial.expectedHarvest || '',
            expectedYield: initial.expectedYield || '',
            seedQuantity: initial.seedQuantity || '',
            seedUnit: initial.seedUnit || 'kg',
            selectedPlotId: initial.selectedPlotId || '',

            match: null,
            hint: '',

            /*
             * DERNIÈRE valeur que NOUS avons posée dans chaque champ.
             *
             * Le recalcul ne remplissait que les champs VIDES : la date de récolte
             * se calculait au chargement puis ne bougeait plus quand on changeait
             * la date de semis — elle restait silencieusement fausse. Mais écraser
             * sans distinction effacerait la saisie d'un technicien qui connaît sa
             * parcelle mieux que le catalogue.
             *
             * On garde donc trace de NOTRE suggestion : si le champ la porte
             * encore, on la recalcule ; s'il a été modifié à la main, on n'y
             * touche plus.
             */
            autoHarvest: null,
            autoYield: null,
            autoSeed: null,

            init() {
                this.resolveMatch();
                // $watch garantit que la propriété est déjà à jour quand le rappel
                // s'exécute, contrairement à @input qui court avec x-model.
                this.$watch('cropName', () => this.resolveMatch());
                this.$watch('variety', () => this.recompute());
                this.$watch('areaHa', () => this.recompute());
                this.$watch('plantingDate', () => this.recompute());
                this.$watch('match', () => this.adoptPlantingUnit());
                // L'équivalence en fruits se lit sous le rendement : elle doit
                // suivre une correction manuelle du rendement, pas seulement le
                // recalcul automatique.
                this.$watch('expectedYield', () => this.buildHint());
                this.$watch('selectedPlotId', (pid) => {
                    if (Object.keys(this.plotData).length === 0) return; // édition : surface figée
                    this.maxAreaHa = (pid && this.plotData[pid]) ? this.plotData[pid].remaining_ha : null;
                });
            },

            areaExceedsLimit() {
                if (this.maxAreaHa === null || !this.areaHa) return false;
                return parseFloat(this.areaHa) > this.maxAreaHa + 0.0001;
            },

            resolveMatch() {
                const needle = (this.cropName || '').trim().toLowerCase();
                this.match = this.catalogue.find(s => s.name.toLowerCase() === needle) || null;
                this.recompute();
            },

            /** Variété sélectionnée dans le catalogue (si elle existe). */
            currentVariety() {
                if (!this.match) return null;
                const needle = (this.variety || '').trim().toLowerCase();
                return this.match.varieties.find(v => v.name.toLowerCase() === needle) || null;
            },

            /** Jours de cycle effectifs : variété > espèce (max). */
            effectiveCycleDays() {
                const v = this.currentVariety();
                if (v && v.cycle_days) return v.cycle_days;
                if (this.match && this.match.cycle_days_max) return this.match.cycle_days_max;
                if (this.match && this.match.cycle_days_min) return this.match.cycle_days_min;
                return null;
            },

            /** Rendement de référence effectif (t/ha) : variété > espèce. */
            effectiveYieldTha() {
                const v = this.currentVariety();
                if (v && v.avg_yield_tha) return v.avg_yield_tha;
                if (this.match && this.match.avg_yield_tha) return this.match.avg_yield_tha;
                return null;
            },

            /** Libellé du champ de quantité, adapté à la culture. */
            plantingLabel() {
                const material = this.match && this.match.planting_material ? this.match.planting_material : 'semence';
                const unit = this.match && this.match.planting_unit ? this.match.planting_unit : (this.seedUnit || 'kg');
                const plural = {
                    'semence': 'Quantité de semences',
                    'plant': 'Nombre de plants',
                    'rejet': 'Nombre de rejets',
                    'bouture': 'Nombre de boutures',
                    'greffon': 'Nombre de greffons',
                    'tubercule': 'Quantité de tubercules',
                    'rhizome': 'Quantité de rhizomes',
                }[material] || ('Quantité de ' + material);

                return plural + ' (' + unit + ')';
            },

            /** Unité imposée par la culture (rejets à l'unité, semences en kg). */
            adoptPlantingUnit() {
                if (this.match && this.match.planting_unit) {
                    this.seedUnit = this.match.planting_unit;
                }
            },

            /** Quantité de plantation suggérée par la densité de référence. */
            suggestedSeedQuantity() {
                if (!this.match || !this.match.planting_density) return null;
                const area = parseFloat(this.areaHa);
                if (!(area > 0)) return null;

                const quantity = this.match.planting_density * area;

                // Un demi-rejet n'existe pas : on arrondit ce qui se compte.
                return this.match.planting_unit === 'kg'
                    ? Math.round(quantity * 100) / 100
                    : Math.round(quantity);
            },

            densityHint() {
                if (!this.match || !this.match.planting_density) return '';
                const unit = this.match.planting_unit || 'kg';

                return 'Densité de référence : ' + this.match.planting_density.toLocaleString('fr-FR')
                    + ' ' + unit + '/ha — ajustez selon votre écartement réel.';
            },

            /** Pluriel du nom de l'unité récoltée : « fruit » → « fruits ». */
            harvestUnitPlural(count) {
                const label = this.match && this.match.harvest_unit_label;
                if (!label) return null;

                return (count !== null && count !== undefined && Math.abs(count) <= 1) ? label : label + 's';
            },

            /**
             * ÉQUIVALENCE du rendement en unités récoltées.
             *
             * Le rendement reste un POIDS, et c'est juste : le kilo porte le prix
             * de vente, donc la marge, et une récolte se pèse. Mais un producteur
             * d'ananas plante des rejets, vend des fruits et raisonne en fruits :
             * « 50 000 kg » ne lui dit rien tant qu'il ne sait pas que cela fait
             * environ 33 000 fruits.
             *
             * Sans poids moyen au catalogue, on n'affiche RIEN : on ne devine pas
             * un calibre.
             */
            yieldHint() {
                const weight = this.match && this.match.avg_unit_weight_kg;
                const kg = parseFloat(this.expectedYield);

                if (!weight || !(kg > 0)) {
                    // Pas de poids moyen : on explique au moins pourquoi le
                    // rendement est en kilos, là où le doute naît.
                    if (this.match && this.match.planting_unit && this.match.planting_unit !== 'kg') {
                        return 'Le rendement se mesure en kilos, même quand la plantation se compte à l’unité :'
                            + ' c’est le poids qui porte le prix de vente et la marge.';
                    }
                    return '';
                }

                const units = Math.round(kg / weight);
                const label = this.harvestUnitPlural(units) || 'unités';

                return '≈ ' + units.toLocaleString('fr-FR') + ' ' + label
                    + ' (poids moyen ' + weight.toLocaleString('fr-FR') + ' kg).';
            },

            buildHint() {
                if (!this.match) { this.hint = ''; return; }
                const parts = [];
                if (this.match.local_name) parts.push('Nom local : ' + this.match.local_name);
                const days = this.effectiveCycleDays();
                if (days) parts.push('Cycle ≈ ' + days + ' j');
                const tha = this.effectiveYieldTha();
                if (tha) parts.push('Rdt réf. ' + tha + ' t/ha');
                this.hint = (parts.length ? this.match.name + ' — ' + parts.join(' · ') : this.match.name)
                    + ' · cliquez pour pré-remplir';
            },

            /** Valeurs suggérées : date de récolte et rendement attendu. */
            suggestions() {
                const out = { harvest: null, yield: null };
                const days = this.effectiveCycleDays();
                if (this.plantingDate && days) {
                    const d = new Date(this.plantingDate);
                    d.setDate(d.getDate() + parseInt(days, 10));
                    out.harvest = d.toISOString().slice(0, 10);
                }
                const tha = this.effectiveYieldTha();
                const area = parseFloat(this.areaHa);
                if (tha && area > 0) {
                    out.yield = Math.round(tha * area * 1000); // t/ha → kg
                }
                return out;
            },

            /**
             * Un champ est-il « à nous » ? Vide, ou portant encore exactement la
             * valeur que nous y avons mise. Comparaison en CHAÎNE : Alpine renvoie
             * du texte depuis les <input>, un 50000 saisi ne doit pas différer d'un
             * 50000 calculé.
             */
            isOurs(current, mine) {
                if (current === '' || current === null || current === undefined) return true;
                return mine !== null && String(current) === String(mine);
            },

            /** Applique explicitement les suggestions (bouton « Pré-remplir »). */
            applySuggestions() {
                const s = this.suggestions();
                if (s.harvest) { this.expectedHarvest = s.harvest; this.autoHarvest = s.harvest; }
                if (s.yield !== null) { this.expectedYield = s.yield; this.autoYield = s.yield; }
                this.adoptPlantingUnit();
                const seed = this.suggestedSeedQuantity();
                if (seed !== null) { this.seedQuantity = seed; this.autoSeed = seed; }
            },

            /** Recalcule ce qui est encore à nous ; laisse intacte toute saisie humaine. */
            recompute() {
                this.buildHint();
                if (!this.match) return;

                const s = this.suggestions();

                if (s.harvest && this.isOurs(this.expectedHarvest, this.autoHarvest)) {
                    this.expectedHarvest = s.harvest;
                    this.autoHarvest = s.harvest;
                }
                if (s.yield !== null && this.isOurs(this.expectedYield, this.autoYield)) {
                    this.expectedYield = s.yield;
                    this.autoYield = s.yield;
                }

                const seed = this.suggestedSeedQuantity();
                if (seed !== null && this.isOurs(this.seedQuantity, this.autoSeed)) {
                    this.seedQuantity = seed;
                    this.autoSeed = seed;
                }
            },
        };
    }
</script>

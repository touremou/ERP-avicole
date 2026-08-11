-- ═══════════════════════════════════════════════════════════════════════════
-- DIAGNOSTIC — POIDS DU SAC D'ALIMENT : QUELS ACHATS SONT CONCERNÉS ?
--
-- LECTURE SEULE. Aucune de ces requêtes ne modifie quoi que ce soit : elles ne
-- contiennent que des SELECT. On peut les exécuter en production sans risque,
-- une par une, dans phpMyAdmin.
--
-- ───────────────────────────────────────────────────────────────────────────
-- CE QUI ÉTAIT FAUX, EXACTEMENT
--
-- `CreateFeedPurchase` calculait le coût au kilo d'un achat en sacs avec un
-- poids de sac de 50 kg CODÉ EN DUR, en ignorant le réglage
-- « general.feed_bag_weight ».
--
--   • LE COÛT AU KILO était donc faux dès que le réglage différait de 50.
--     Avec un réglage à 25 kg : coût au kilo DIVISÉ PAR DEUX. Ce coût alimente
--     le coût moyen pondéré (CMP) de l'article, donc le coût de revient de
--     toute bande nourrie sur ce stock — sous-évalué d'autant.
--
--   • LA QUANTITÉ en magasin, elle, était CORRECTE : elle passait par un autre
--     chemin, qui lisait bien le réglage. (Une première analyse annonçait un
--     stock doublé : c'était FAUX, et vérifié depuis en rejouant le code
--     d'avant le correctif.)
--
--   • SEULE EXCEPTION pour la quantité : un achat portant un poids de sac qui
--     lui est propre (`metadata.bag_weight`) voyait son coût calculé sur ce
--     poids-là, mais sa quantité convertie sur le réglage général. Quantité
--     fausse pour ces achats-là seulement.
--
-- SI LE RÉGLAGE N'A JAMAIS ÉTÉ MODIFIÉ (resté à 50), RIEN N'EST FAUX.
-- La requête 1 le dit en une ligne.
-- ═══════════════════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────────────────
-- 1. LE RÉGLAGE A-T-IL ÉTÉ TOUCHÉ ? (à lancer en premier)
--
-- Si `valeur_actuelle` = 50 ET qu'aucune ligne ne sort de la requête 2, aucun
-- achat n'est concerné : on peut s'arrêter ici.
-- ───────────────────────────────────────────────────────────────────────────
SELECT
    `value`                       AS valeur_actuelle,
    updated_at                    AS derniere_modification,
    CASE WHEN `value` = '50' THEN 'Aucun achat concerné par le coût'
         ELSE 'Coûts au kilo à vérifier (requête 3)'
    END                           AS verdict
FROM settings
WHERE `group` = 'general' AND `key` = 'feed_bag_weight' AND farm_id IS NULL;


-- ───────────────────────────────────────────────────────────────────────────
-- 2. HISTORIQUE DES CHANGEMENTS DU RÉGLAGE
--
-- Donne les DATES à partir desquelles le coût des achats devient faux. Avant le
-- premier changement, le réglage valait 50 et le calcul était juste.
-- ───────────────────────────────────────────────────────────────────────────
SELECT
    created_at                    AS date_du_changement,
    old_value                     AS ancienne_valeur,
    new_value                     AS nouvelle_valeur,
    user_id                       AS modifie_par
FROM setting_audits
WHERE `group` = 'general' AND `key` = 'feed_bag_weight'
ORDER BY created_at;


-- ───────────────────────────────────────────────────────────────────────────
-- 3. LES ACHATS DONT LE COÛT AU KILO EST FAUX, ET DE COMBIEN
--
-- ⚠️  REMPLACER `25` PAR LE POIDS DE SAC RÉELLEMENT EN VIGUEUR à la date des
--     achats (cf. requêtes 1 et 2). Si le réglage a changé plusieurs fois,
--     relancer une fois par période en ajustant aussi la clause de dates.
--
-- Ne liste que les achats en SACS sans poids propre : ceux-là ont été calculés
-- à 50 kg.
-- ───────────────────────────────────────────────────────────────────────────
SELECT
    fp.id,
    fp.purchase_date              AS date_achat,
    fp.feed_type                  AS article,
    fp.quantity                   AS nb_sacs,
    fp.total_price                AS prix_total,

    -- Ce que le système a retenu (50 kg/sac) :
    ROUND(fp.total_price / NULLIF(fp.quantity * 50, 0), 2)   AS cout_kg_enregistre,

    -- Ce qu'il aurait dû retenir (poids réel — À AJUSTER) :
    ROUND(fp.total_price / NULLIF(fp.quantity * 25, 0), 2)   AS cout_kg_correct,

    -- Facteur d'erreur : 2 signifie « le coût réel est le double de l'enregistré ».
    ROUND(50 / 25, 4)                                        AS facteur_erreur,

    -- Kilos réellement entrés en magasin (cette valeur, elle, était juste) :
    fp.quantity * 25                                         AS kg_reels
FROM feed_purchases fp
WHERE fp.unit = 'Sac'
  AND (fp.metadata IS NULL OR JSON_EXTRACT(fp.metadata, '$.bag_weight') IS NULL)
  -- Restreindre à la période où le réglage n'était plus 50 :
  -- AND fp.purchase_date >= '2026-01-01'
ORDER BY fp.purchase_date DESC;


-- ───────────────────────────────────────────────────────────────────────────
-- 4. LE CAS PLUS RARE : ACHATS DONT LA QUANTITÉ EST FAUSSE
--
-- Achats portant un poids de sac PROPRE, différent du réglage général : leur
-- coût suivait ce poids propre, mais leur quantité a été convertie sur le
-- réglage. Ce sont les seuls achats dont les KILOS en magasin sont faux.
--
-- ⚠️  REMPLACER `25` par le réglage en vigueur.
-- ───────────────────────────────────────────────────────────────────────────
SELECT
    fp.id,
    fp.purchase_date              AS date_achat,
    fp.feed_type                  AS article,
    fp.quantity                   AS nb_sacs,
    CAST(JSON_UNQUOTE(JSON_EXTRACT(fp.metadata, '$.bag_weight')) AS DECIMAL(10,2))
                                  AS poids_propre_a_l_achat,
    25                            AS reglage_general,

    -- Kilos crédités (sur le réglage) vs kilos réels (sur le poids propre) :
    fp.quantity * 25              AS kg_credites,
    fp.quantity * CAST(JSON_UNQUOTE(JSON_EXTRACT(fp.metadata, '$.bag_weight')) AS DECIMAL(10,2))
                                  AS kg_reels,
    fp.quantity * (CAST(JSON_UNQUOTE(JSON_EXTRACT(fp.metadata, '$.bag_weight')) AS DECIMAL(10,2)) - 25)
                                  AS ecart_kg
FROM feed_purchases fp
WHERE fp.unit = 'Sac'
  AND JSON_EXTRACT(fp.metadata, '$.bag_weight') IS NOT NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(fp.metadata, '$.bag_weight')) AS DECIMAL(10,2)) <> 25
ORDER BY fp.purchase_date DESC;


-- ───────────────────────────────────────────────────────────────────────────
-- 5. AMPLEUR GLOBALE — combien d'achats, quel volume d'argent
--
-- Pour décider s'il vaut la peine de reprendre l'historique. Si le nombre est
-- faible, une correction manuelle des quelques articles concernés suffit.
-- ───────────────────────────────────────────────────────────────────────────
SELECT
    COUNT(*)                                                  AS nb_achats_en_sacs,
    SUM(CASE WHEN fp.metadata IS NULL
              OR JSON_EXTRACT(fp.metadata, '$.bag_weight') IS NULL
             THEN 1 ELSE 0 END)                                AS dont_cout_calcule_a_50kg,
    SUM(fp.total_price)                                        AS montant_total_gnf,
    MIN(fp.purchase_date)                                      AS premier_achat,
    MAX(fp.purchase_date)                                      AS dernier_achat
FROM feed_purchases fp
WHERE fp.unit = 'Sac';


-- ───────────────────────────────────────────────────────────────────────────
-- 6. ÉTAT ACTUEL DES ARTICLES D'ALIMENT (pour comparer au magasin physique)
--
-- Le CMP (`unit_price`) de ces articles a été tiré vers le bas par les achats
-- de la requête 3. La quantité, elle, doit correspondre au stock physique.
-- ───────────────────────────────────────────────────────────────────────────
SELECT
    s.item_name                   AS article,
    s.unit                        AS unite,
    s.current_quantity            AS quantite,
    s.unit_price                  AS cout_moyen_pondere,
    ROUND(s.current_quantity * s.unit_price)  AS valeur_stock_gnf,
    s.updated_at                  AS derniere_ecriture
FROM stocks s
WHERE s.category = 'conso'
  AND s.deleted_at IS NULL
ORDER BY s.item_name;


-- ═══════════════════════════════════════════════════════════════════════════
-- CE QUE CE DIAGNOSTIC NE FAIT PAS
--
-- Il ne corrige rien, et il ne recalcule pas le coût de revient des bandes déjà
-- clôturées. Un tel recalcul toucherait des chiffres déjà publiés (comptes de
-- résultat, coûts de revient de bandes vendues) : c'est une décision de gestion,
-- pas une opération technique.
--
-- Le correctif applicatif porte sur les écritures FUTURES : à partir de son
-- déploiement, le coût au kilo et la quantité suivent le même poids de sac.
-- ═══════════════════════════════════════════════════════════════════════════

<?php

namespace App\Console\Commands;

use App\Models\DailyCheck;
use App\Models\Farm;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DIAGNOSTIC DE L'INSTALLATION — « le code va bien, le déploiement non ».
 *
 * ─── POURQUOI CETTE COMMANDE EXISTE ───
 *
 * Presque toutes les difficultés remontées du terrain n'étaient pas des défauts de
 * code mais des états d'installation invisibles :
 *
 *   • le canal WhatsApp en mode « journal » — AUCUNE alerte ne part, et rien ne le
 *     dit sur les écrans ;
 *   • les clefs de notification jamais générées — le push est impossible ;
 *   • l'expéditeur e-mail différent de l'identifiant SMTP — « Failed to
 *     authenticate » sans explication ;
 *   • un compte non rattaché à son site — TOUTES ses saisies mobiles refusées, avec
 *     un message parlant de « bâtiment invalide », introuvable pour qui cherche la
 *     vraie cause ;
 *   • un pointage sans coût d'aliment — le coût de revient de la bande est
 *     incomplet, et le chiffre s'affiche quand même.
 *
 * Chacun de ces états a coûté des allers-retours. Aucun n'était visible d'un coup
 * d'œil. Cette commande les rend visibles, avec le geste exact pour y remédier.
 *
 * ─── CE QU'ELLE N'EST PAS ───
 *
 * Elle ne répare rien et n'écrit rien : elle LIT. Réparer demanderait des décisions
 * (quel provider, quel numéro, quel poids de sac) qui appartiennent à
 * l'exploitation.
 *
 * Et elle ne prétend pas voir ce qu'elle ne peut pas voir : si le planificateur de
 * tâches n'est pas branché côté hébergeur, aucune commande lancée à la main ne peut
 * l'établir. Elle le dit, plutôt que de rendre un verdict qu'elle n'a pas les moyens
 * de porter.
 */
class InstallationDiagnostic extends Command
{
    protected $signature = 'avismart:diagnostic';

    protected $description = "Vérifie l'état de l'installation : alertes, comptes, réglages, cohérence des coûts";

    /** @var array<int, array{niveau: string, sujet: string, constat: string, remede: ?string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('<options=bold>DIAGNOSTIC DE L’INSTALLATION</> — lecture seule, rien n’est modifié.');

        $this->checkOutboundChannels();
        $this->checkAccountsAndSites();
        $this->checkCriticalSettings();
        $this->checkCostIntegrity();
        $this->checkBackups();
        $this->checkScheduler();

        return $this->render();
    }

    // ─────────────────────────────────────────────────────────────
    //  1. LES ALERTES SORTENT-ELLES ?
    // ─────────────────────────────────────────────────────────────
    private function checkOutboundChannels(): void
    {
        $driver = (string) setting('whatsapp.driver', 'log');

        if ($driver === 'log') {
            $this->blocking('WhatsApp', 'Canal en mode « journal » : les messages sont écrits dans les logs et n’atteignent PERSONNE.',
                'Paramètres › WhatsApp → choisir un driver (twilio, ultramsg, wati, callmebot) et renseigner la clé API.');
        } else {
            $this->healthy('WhatsApp', "Driver actif : {$driver}.");

            $key = (string) setting('whatsapp.api_key', '');

            if (blank($key)) {
                $this->blocking('WhatsApp', "Driver « {$driver} » choisi, mais la clé API est vide : chaque envoi échouera.",
                    'Paramètres › WhatsApp → renseigner la clé API.');
            } elseif ($driver === 'twilio' && ! str_contains($key, ':')) {
                // Twilio exige DEUX identifiants ; la clé unique de l'écran les
                // porte séparés par deux-points (cf. WhatsAppService).
                $this->blocking('WhatsApp', 'Driver Twilio : la clé API doit être au format « SID:TOKEN ». Sans les deux moitiés, l’authentification échoue (401) à chaque envoi.',
                    'Paramètres › WhatsApp → clé API = identifiant de compte, deux-points, jeton.');
            }

            if ($driver === 'twilio' && blank(setting('whatsapp.sender', '')) && blank(config('whatsapp.twilio_from'))) {
                $this->attention('WhatsApp', 'Aucun numéro expéditeur : le bac à sable de Twilio sera utilisé, et il n’écrit qu’aux numéros pré-autorisés.',
                    'Paramètres › WhatsApp → numéro expéditeur.');
            }
        }

        if (blank(setting('whatsapp.admin_phone', ''))) {
            $this->attention('WhatsApp', 'Aucun « téléphone admin » : le filet de secours des alertes critiques est inactif.',
                'Paramètres › WhatsApp → téléphone admin.');
        }

        // ─── Push (notifications qui font sonner le téléphone, app fermée) ───
        $vapid = filled(setting('push.vapid_public_key', '')) && filled(setting('push.vapid_private_key', ''));

        if (! $vapid) {
            $this->blocking('Push', 'Clefs de notification jamais générées : aucun téléphone ne peut être averti, application fermée.',
                'Lancer une fois : php artisan push:generate-keys');
        } else {
            $devices = Schema::hasTable('push_subscriptions')
                ? DB::table('push_subscriptions')->count()
                : 0;

            $devices === 0
                ? $this->attention('Push', 'Clefs présentes, mais AUCUN appareil abonné : personne ne recevra de bannière.',
                    'Sur chaque téléphone : Mon espace → autoriser les notifications.')
                : $this->healthy('Push', "{$devices} appareil(s) abonné(s).");
        }

        // ─── E-mail ───
        if (! MailSettings::configured() && blank(config('mail.mailers.smtp.host'))) {
            $this->attention('E-mail', 'Aucun serveur SMTP configuré : les alertes par e-mail ne partiront pas.',
                'Réglages › E-mail (SMTP) → hôte, port, identifiant, mot de passe.');
        } else {
            $username = (string) (Setting::get('mail.username') ?: config('mail.mailers.smtp.username'));
            $from = (string) (Setting::get('mail.from_address') ?: config('mail.from.address'));

            if (filled($username) && filled($from) && $username !== $from) {
                // Cause n°1 des « Failed to authenticate » sur hébergement mutualisé :
                // la plupart des serveurs refusent d'expédier au nom d'une autre boîte.
                $this->attention('E-mail', "L’expéditeur ({$from}) diffère de la boîte authentifiée ({$username}). La plupart des hébergements mutualisés refusent cet envoi.",
                    'Réglages › E-mail → mettre l’adresse d’expédition égale à l’identifiant, ou la laisser vide.');
            } else {
                $this->healthy('E-mail', 'Serveur configuré, expéditeur cohérent.');
            }

            if ((string) (Setting::get('mail.mailer') ?: config('mail.default')) === 'log') {
                $this->blocking('E-mail', 'Canal en mode « log » : les messages sont écrits dans les journaux et n’arrivent à personne.',
                    'Réglages › E-mail → repasser le canal sur « smtp ».');
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  2. LES COMPTES PEUVENT-ILS TRAVAILLER ?
    // ─────────────────────────────────────────────────────────────
    private function checkAccountsAndSites(): void
    {
        // Farm::active() et NON withoutGlobalScopes() : sur ce modèle, ce dernier ne
        // retire que la protection des SUPPRESSIONS (Farm n'a pas de scope de ferme).
        // Un site supprimé était donc compté comme actif — défaut que j'avais
        // introduit ici même en #213.
        $farms = Farm::active()->count();

        $farms === 0
            ? $this->blocking('Sites', 'Aucun site actif : plus aucun écran ne fonctionne.', 'Sites → réactiver au moins un site.')
            : $this->healthy('Sites', "{$farms} site(s) actif(s).");

        // Un compte sans rattachement voit TOUTES ses saisies mobiles refusées, et
        // le message parle de « bâtiment invalide » : la vraie cause est
        // introuvable pour qui la cherche.
        // Requête directe sur le pivot : le modèle User n'expose pas de relation
        // `farms()`, seul Farm::users() existe. Supposer la relation inverse aurait
        // fait échouer la commande à l'exécution — sur une commande de diagnostic,
        // ce serait le comble.
        $orphans = User::query()
            ->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('farm_user')
                ->whereColumn('farm_user.user_id', 'users.id'))
            ->pluck('name');

        $orphans->isEmpty()
            ? $this->healthy('Comptes', 'Tous les comptes actifs sont rattachés à un site.')
            : $this->blocking('Comptes', 'Comptes actifs SANS site : leurs saisies mobiles sont toutes refusées (« bâtiment invalide »). — ' . $orphans->implode(', '),
                'Utilisateurs → affecter chaque compte à son site.');

        // Un rôle sans aucune permission ne peut rien ouvrir : l'utilisateur voit
        // un écran vide et croit à une panne.
        if (Schema::hasTable('module_permissions')) {
            // Là encore, requête sur la table plutôt que sur une relation supposée :
            // Role n'expose pas `modulePermissions()`.
            $empty = Role::query()
                ->where('name', '!=', 'admin')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('module_permissions')
                    ->whereColumn('module_permissions.role_id', 'roles.id')
                    ->where(fn ($w) => $w->where('can_read', true)->orWhere('can_create', true)
                        ->orWhere('can_modify', true)->orWhere('can_delete', true)))
                ->pluck('name');

            $empty->isEmpty()
                ? $this->healthy('Rôles', 'Chaque rôle a au moins une permission.')
                : $this->attention('Rôles', 'Rôles sans aucune permission — leurs comptes ne verront que des écrans vides : ' . $empty->implode(', '),
                    'Utilisateurs › Rôles → cocher au moins une lecture.');
        }

        $noPhone = User::query()->where('is_active', true)->whereNull('whatsapp_phone')->count();

        if ($noPhone > 0) {
            $this->attention('Comptes', "{$noPhone} compte(s) actif(s) sans numéro WhatsApp : ils ne recevront rien sur ce canal (la cloche, elle, fonctionne).",
                'Profil de chaque utilisateur → numéro WhatsApp.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  3. LES RÉGLAGES QUI CHANGENT LES CHIFFRES
    // ─────────────────────────────────────────────────────────────
    private function checkCriticalSettings(): void
    {
        $company = (string) Setting::companyName();

        $company === 'AviSmart'
            ? $this->attention('Identité', 'Le nom d’exploitation est resté « AviSmart » : il signe les relevés clients, tickets et bulletins de paie.',
                'Réglages › Général → nom de l’entreprise.')
            : $this->healthy('Identité', "Documents signés « {$company} ».");

        // Identité de l'émetteur : elle signe factures, tickets, reçus et bulletins.
        // Une mention absente ne se voit pas à l'écran — elle se voit sur le
        // document que le client a déjà reçu.
        $identite = Setting::companyIdentity();

        $manquants = collect([
            'adresse'   => $identite['address'],
            'téléphone' => $identite['phone'],
            'NIF'       => $identite['fiscal_id'],
            'RCCM'      => $identite['rccm'],
        ])->filter(fn ($v) => blank($v))->keys();

        $manquants->isEmpty()
            ? $this->healthy('Documents', 'Factures, tickets, reçus et bulletins portent une identité complète.')
            : $this->attention('Documents', 'Mention(s) absente(s) de TOUS les documents sortants : ' . $manquants->implode(', ') . '. Une facture sans NIF ni RCCM n’est pas un justificatif recevable.',
                'Réglages › Général → adresse, téléphone, N° Identification Fiscale, N° RCCM.');

        $bagWeight = (float) setting('general.feed_bag_weight', 50);

        if ($bagWeight != 50.0) {
            // Les achats antérieurs au correctif ont un coût au kilo calculé à 50 kg.
            $this->attention('Coût aliment', "Poids de sac réglé à {$bagWeight} kg. Les achats en sacs enregistrés AVANT le correctif ont un coût au kilo calculé à 50 kg — donc faux.",
                'Lancer php artisan feed:recompute-costs (simulation), puis --force après lecture du rapport.');
        }

        $sanitary = \App\Models\Building::sanitaryBreakDays();
        $this->healthy('Vide sanitaire', "{$sanitary} jours — appliqué à l’affichage, au planning ET à la libération automatique.");

        // Les quatre réglages d'heure sont saisis à la main. Une valeur illisible
        // n'arrête plus le planificateur (Setting::hour la remplace), mais elle
        // signifie que l'heure choisie par l'exploitation N'EST PAS celle qui
        // s'applique — et rien d'autre ne le dirait.
        $badHours = Setting::whereNull('farm_id')->where('unit', 'HH:MM')->get()
            ->filter(fn ($s) => filled($s->value) && Setting::normalizeHour((string) $s->value) === null)
            ->map(fn ($s) => "{$s->label} = « {$s->value} »");

        $badHours->isEmpty()
            ? $this->healthy('Heures réglées', 'Les quatre heures paramétrées sont lisibles.')
            : $this->attention('Heures réglées', 'Heure(s) illisible(s) — la valeur de repli s’applique, pas celle saisie : ' . $badHours->implode(' ; '),
                'Réglages → ressaisir au format HH:MM (00:00 à 23:59).');
    }

    // ─────────────────────────────────────────────────────────────
    //  4. LES CHIFFRES SONT-ILS COMPLETS ?
    // ─────────────────────────────────────────────────────────────
    private function checkCostIntegrity(): void
    {
        // Un pointage qui consomme de l'aliment sans coût unitaire laisse un TROU
        // dans le coût de revient de la bande — et le total s'affiche quand même.
        $withoutCost = DailyCheck::withoutGlobalScopes()
            ->where('feed_consumed', '>', 0)
            ->where(fn ($q) => $q->whereNull('feed_unit_cost')->orWhere('feed_unit_cost', '<=', 0))
            ->count();

        $withoutCost === 0
            ? $this->healthy('Coût de revient', 'Toute consommation d’aliment porte un coût unitaire.')
            : $this->attention('Coût de revient', "{$withoutCost} pointage(s) consomment de l’aliment SANS coût unitaire : le coût de revient des bandes concernées est incomplet.",
                'Vérifier que les articles d’aliment ont un prix, puis php artisan feed:recompute-costs.');

        // Un article d'aliment à coût nul fera entrer de la consommation gratuite
        // dans le coût de revient.
        $freeFeed = Stock::withoutGlobalScopes()
            ->where('category', Stock::CAT_CONSO)
            ->where(fn ($q) => $q->whereNull('unit_price')->orWhere('unit_price', '<=', 0))
            ->where('current_quantity', '>', 0)
            ->pluck('item_name');

        $freeFeed->isEmpty()
            ? $this->healthy('Magasin', 'Tous les articles en stock ont un coût.')
            : $this->attention('Magasin', 'Articles en stock SANS coût — leur consommation sera comptée gratuite : ' . $freeFeed->implode(', '),
                'Logistique › Inventaire → renseigner le prix unitaire, ou enregistrer un achat.');
    }

    // ─────────────────────────────────────────────────────────────
    //  5. LES SAUVEGARDES
    // ─────────────────────────────────────────────────────────────
    //
    // L'enjeu le plus lourd de tous, et le seul irréversible : une panne de disque
    // sans sauvegarde met fin à l'exploitation. Tout le reste se rattrape.
    private function checkBackups(): void
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('backups');
            $fichiers = $disk->allFiles();
        } catch (\Throwable $e) {
            $this->blocking('Sauvegardes', 'Le disque de sauvegarde est injoignable : ' . $e->getMessage(),
                'Vérifier la configuration du disque « backups » et les droits d’écriture.');

            return;
        }

        if ($fichiers === []) {
            $this->blocking('Sauvegardes', 'AUCUNE sauvegarde n’existe. Une panne de disque effacerait l’exploitation — c’est le seul incident de cette liste qui ne se rattrape pas.',
                'Lancer une fois php artisan backup:run, puis vérifier que le cron de l’hébergeur tourne.');

            return;
        }

        $dernier = collect($fichiers)
            ->map(fn ($f) => $disk->lastModified($f))
            ->max();

        $age = now()->diffInHours(\Carbon\Carbon::createFromTimestamp($dernier), absolute: true);

        if ($age > 48) {
            $jours = round($age / 24, 1);

            $this->blocking('Sauvegardes', "La dernière sauvegarde date de {$jours} jour(s). La sauvegarde quotidienne ne tourne donc plus.",
                'Vérifier le cron de l’hébergeur (schedule:run), puis php artisan backup:run.');
        } else {
            $this->healthy('Sauvegardes', count($fichiers) . " sauvegarde(s), la plus récente il y a {$age} h.");
        }

        // Une sauvegarde à côté des données ne protège que de l'effacement, pas de
        // la perte de la machine.
        $disques = (array) config('backup.backup.destination.disks', []);

        count($disques) > 1
            ? $this->healthy('Sauvegardes hors site', 'Copie sur ' . count($disques) . ' destination(s).')
            : $this->attention('Sauvegardes hors site', 'Les sauvegardes ne partent QUE sur le disque du serveur : une panne matérielle emporterait les données ET leurs sauvegardes.',
                'Configurer BACKUP_DISKS=backups,backups_offsite (voir docs/ops/backup-restore-runbook.md).');
    }

    // ─────────────────────────────────────────────────────────────
    //  6. LE PLANIFICATEUR
    // ─────────────────────────────────────────────────────────────
    private function checkScheduler(): void
    {
        /*
         * AUCUNE commande lancée à la main ne peut interroger le cron de
         * l'hébergeur. Mais elle peut en observer la TRACE : la sauvegarde
         * quotidienne de 02:00 est déclenchée par le planificateur, et par lui seul.
         * Une sauvegarde datée de cette nuit est donc une PREUVE que le cron tourne.
         *
         * C'est mieux qu'un « non vérifiable » : le fait est établi par ce qu'il a
         * laissé, à défaut de pouvoir l'être en le regardant faire.
         */
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('backups');
            $recent = collect($disk->allFiles())->map(fn ($f) => $disk->lastModified($f))->max();
        } catch (\Throwable) {
            $recent = null;
        }

        if ($recent && now()->diffInHours(\Carbon\Carbon::createFromTimestamp($recent), absolute: true) <= 36) {
            $this->healthy('Tâches planifiées', 'Le planificateur TOURNE : la sauvegarde automatique de cette nuit en est la trace.');

            return;
        }

        $this->attention('Tâches planifiées', 'NON VÉRIFIABLE d’ici, et aucune sauvegarde récente n’en atteste. Si le cron de l’hébergeur n’appelle pas « php artisan schedule:run » chaque minute, AUCUNE alerte planifiée ne partira (contrats, pointages manquants, péremptions, résumé quotidien).',
            'cPanel › Tâches cron → * * * * * cd /chemin && php artisan schedule:run >> /dev/null 2>&1');
    }

    // ─── Rendu ───
    //
    // Les trois méthodes ci-dessous s'appelaient ok/warn/fail. `fail()` est une
    // méthode PUBLIQUE de Illuminate\Console\Command : la redéclarer en privé
    // provoque une erreur FATALE au chargement de la classe, avant même
    // l'exécution — « Access level to ... must be public ».
    //
    // C'est le défaut exact qui a cassé la production le 14 juillet
    // (CheckHaccpRegisters::alert(), même cause). Il est désormais interdit par
    // ConsoleCommandSignatureTest, qui l'aurait attrapé alors.

    private function healthy(string $sujet, string $constat): void
    {
        $this->findings[] = ['niveau' => 'ok', 'sujet' => $sujet, 'constat' => $constat, 'remede' => null];
    }

    private function attention(string $sujet, string $constat, ?string $remede = null): void
    {
        $this->findings[] = ['niveau' => 'attention', 'sujet' => $sujet, 'constat' => $constat, 'remede' => $remede];
    }

    private function blocking(string $sujet, string $constat, ?string $remede = null): void
    {
        $this->findings[] = ['niveau' => 'bloquant', 'sujet' => $sujet, 'constat' => $constat, 'remede' => $remede];
    }

    private function render(): int
    {
        $order = ['bloquant' => 0, 'attention' => 1, 'ok' => 2];

        usort($this->findings, fn ($a, $b) => $order[$a['niveau']] <=> $order[$b['niveau']]);

        $blocking = 0;

        foreach ($this->findings as $f) {
            [$tag, $colour] = match ($f['niveau']) {
                'bloquant'  => ['BLOQUANT ', 'red'],
                'attention' => ['ATTENTION', 'yellow'],
                default     => ['OK       ', 'green'],
            };

            if ($f['niveau'] === 'bloquant') {
                $blocking++;
            }

            $this->line('');
            $this->line("<fg={$colour};options=bold>{$tag}</>  <options=bold>{$f['sujet']}</> — {$f['constat']}");

            if ($f['remede']) {
                $this->line("           → {$f['remede']}");
            }
        }

        $this->line('');
        $this->line('─────────────────────────────────────────────');

        $counts = array_count_values(array_column($this->findings, 'niveau'));

        $this->line(sprintf(
            '%d bloquant(s) · %d point(s) d’attention · %d vérification(s) au vert.',
            $counts['bloquant'] ?? 0, $counts['attention'] ?? 0, $counts['ok'] ?? 0
        ));

        // Code de sortie non nul s'il y a du bloquant : utilisable dans un script
        // de surveillance sans lire le texte.
        return $blocking > 0 ? self::FAILURE : self::SUCCESS;
    }
}

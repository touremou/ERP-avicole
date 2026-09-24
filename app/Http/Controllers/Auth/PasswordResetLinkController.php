<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        /*
         * LA MÊME RÉPONSE, QUE LE COMPTE EXISTE OU NON.
         *
         * La réponse d'origine distinguait les deux : « aucun utilisateur n'a
         * cette adresse » d'un côté, « lien envoyé » de l'autre. Le formulaire
         * servait donc d'annuaire — il suffisait d'essayer des adresses pour
         * savoir lesquelles ouvrent un compte. Et les identifiants des employés
         * sont GÉNÉRÉS selon un motif (`EmployeeAccessController::uniqueLogin`),
         * donc devinables, puis confirmables un par un.
         *
         * Le délai entre deux envois (`passwords.users.throttle`) trahissait
         * aussi l'existence du compte : seul un compte existant peut être
         * « trop sollicité ». Il reçoit donc, lui aussi, la réponse commune.
         *
         * Le prix est connu et accepté (recommandation OWASP) : une personne qui
         * se trompe d'adresse n'en est plus avertie. Le message l'oriente vers
         * son administrateur.
         */
        return back()->with('status', __(
            'Si un compte existe pour cette adresse, un lien de réinitialisation vient de lui être envoyé. '
            . 'Rien reçu ? Vérifiez l\'adresse ou contactez votre administrateur.'
        ));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Ui;

use LogicException;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormResult;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function in_array;
use function is_array;

/**
 * Since 1.39.0: the host page behind a dialog URL of the timesheet components (`options.dialog_urls`; every dialog
 * loads its content on demand). One host route (GET and POST) per dialog hands its request here:
 *
 *     #[Route('/team/{id}/ledger/reverse', methods: ['GET', 'POST'])]
 *     public function reverse(Request $request, string $id): Response
 *     {
 *         return $this->dialogs->ledger($request, $this->getUser(), $owner, $scope, $this->generateUrl('team_ledger', ['id' => $id]), $request->query->getString('line'));
 *     }
 *
 * GET answers the form: the fragment for a UiKit remote dialog (`X-Nowo-Dialog: 1`), else the full page with the same
 * form (no JavaScript, or a failed load). POST submits it in place: `422` + the form again when invalid (or when the
 * ledger / service refuses the values), `409` + the form on a conflict (REQ-RUNTIME-003: `conflict`, `stale`,
 * `already_reversed`, a locked period…), `204` + `X-Nowo-Redirect` once saved for a dialog request, a `303` otherwise.
 * The access decision is the action's own ({@see TimesheetFormHandler::ledgerDialogForm()} /
 * {@see TimesheetFormHandler::decisionDialogForm()} on GET, the ledger / service on POST): `forbidden` is a 403 and
 * `not_found` a 404, exactly as the post refuses them. CSRF stays the handler's own check. Stateless.
 */
final readonly class TimesheetDialogResponder
{
    public const FRAGMENT_TEMPLATE = '@NowoTimeTrackBundle/components/dialog_form.html.twig';

    public const PAGE_TEMPLATE = '@NowoTimeTrackBundle/components/dialog_page.html.twig';

    /** Ledger codes answered as a conflict (409) rather than as invalid values (422). */
    private const LEDGER_CONFLICTS = ['conflict', 'already_reversed'];

    private RemoteDialogResponder $dialogs;

    /**
     * Receives the form handler, Twig, the translator and UiKit's remote dialog responder (its header names).
     */
    public function __construct(
        private TimesheetFormHandler $forms,
        private ?Environment $twig,
        private ?TranslatorInterface $translator,
        ?RemoteDialogResponder $dialogs = null,
    ) {
        $this->dialogs = $dialogs ?? new RemoteDialogResponder();
    }

    /**
     * The adjustment dialog of `$owner`'s balance in the scope, or with `$reverses` the reversal of that line.
     *
     * @param string $successUrl Where the page goes once the line is written (same origin)
     * @param array<string, mixed> $options layout (the full page's layout), title, submit_label, cancel_url (default the success URL), flash (translated success flash)
     */
    public function ledger(Request $request, UserInterface $actor, UserInterface $owner, string $scopeKey, string $successUrl, ?string $reverses = null, array $options = []): Response
    {
        $reversal = $reverses !== null && $reverses !== '';
        $options  = $this->options($options, $reversal ? 'ledger.dialog.reversal_title' : 'ledger.dialog.add_title', $reversal ? 'ledger.action.reversal' : 'ledger.action.add_adjustment', $successUrl);
        $testid   = $reversal ? 'time-track-ledger-reversal' : 'time-track-ledger-adjustment';
        $action   = $request->getRequestUri();
        try {
            if (!$request->isMethod(Request::METHOD_POST)) {
                return $this->answer($request, $this->forms->ledgerDialogForm($actor, UserIdResolver::getId($owner), $scopeKey, $action, $reversal ? $reverses : null), $testid, $options, null, Response::HTTP_OK, $reversal);
            }
            $result = $this->forms->submitLedger($request, $actor, $owner, $scopeKey, $reversal, $action);
        } catch (LedgerRefusedException $e) {
            $this->refuse($e->errorCode());

            return $this->answer($request, $this->forms->ledgerForm($action, $reversal ? '' : null), $testid, $options, $e, in_array($e->errorCode(), self::LEDGER_CONFLICTS, true) ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY, $reversal, $request);
        } catch (PeriodWriteRefusedException $e) {
            return $this->answer($request, $this->forms->ledgerForm($action, $reversal ? '' : null), $testid, $options, $e, Response::HTTP_CONFLICT, $reversal, $request);
        }

        return $this->submitted($request, $result, $testid, $options, $successUrl, $reversal);
    }

    /**
     * The dialog of an approver action (`approve`, `reject`, `reopen`) on a timesheet.
     *
     * @param string $successUrl Where the page goes once the action ran (same origin)
     * @param array<string, mixed> $options layout, title, submit_label, cancel_url, flash
     */
    public function decision(Request $request, UserInterface $actor, string $action, string $timesheetId, string $successUrl, array $options = []): Response
    {
        $options = $this->options($options, 'timesheet.dialog.' . $action . '_title', 'timesheet.action.' . $action, $successUrl);
        $testid  = 'time-track-' . $action . '-dialog';
        $url     = $request->getRequestUri();
        $danger  = $action === 'reject';
        try {
            if (!$request->isMethod(Request::METHOD_POST)) {
                return $this->answer($request, $this->forms->decisionDialogForm($actor, $action, $timesheetId, $url), $testid, $options, null, Response::HTTP_OK, $danger);
            }
            $result = $this->forms->submitDecision($request, $actor, $action, $url);
        } catch (TimesheetException $e) {
            $this->refuse($e->errorCode());
            $status = $e->httpStatus() === Response::HTTP_CONFLICT ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->answer($request, $this->forms->decisionForm($action, null, $url), $testid, $options, $e, $status, $danger, $request);
        }

        return $this->submitted($request, $result, $testid, $options, $successUrl, $danger);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function submitted(Request $request, TimesheetFormResult $result, string $testid, array $options, string $successUrl, bool $danger): Response
    {
        if (!$result->isValid()) {
            $error = $result->csrfFailed ? $this->trans('timesheet.error.invalid_request') : null;

            return $this->answer($request, $result->form, $testid, $options, null, Response::HTTP_UNPROCESSABLE_ENTITY, $danger, null, $error);
        }
        $flash   = $options['flash'];
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($flash !== null && $session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return $this->dialogs->redirect($successUrl, Response::HTTP_SEE_OTHER, $request);
    }

    /**
     * Throws the HTTP answer of an access refusal, exactly as the post refuses it.
     */
    private function refuse(string $code): void
    {
        if ($code === 'forbidden') {
            throw new AccessDeniedHttpException('The TimeTrack dialog action was refused: forbidden.');
        }
        if ($code === 'not_found') {
            throw new NotFoundHttpException('The TimeTrack dialog action was refused: not_found.');
        }
    }

    /**
     * Renders the fragment (dialog request) or the full page with the form.
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    private function answer(Request $request, FormInterface $form, string $testid, array $options, ?Throwable $refusal, int $status, bool $danger, ?Request $submitted = null, ?string $error = null): Response
    {
        if ($submitted instanceof Request && !$form->isSubmitted()) {
            // A refused post shows the values the user typed (the form is not re-validated, nothing more runs).
            $typed = $submitted->request->all()[$form->getName()] ?? [];
            $form->submit(is_array($typed) ? $typed : [], false);
        }
        if ($refusal instanceof TimesheetException || $refusal instanceof LedgerRefusedException || $refusal instanceof PeriodWriteRefusedException) {
            $error = $this->trans('timesheet.error.' . $refusal->errorCode());
        }
        $context = [
            'form'         => $form->createView(),
            'form_id'      => $testid . '-form',
            'testid'       => $testid,
            'title'        => $options['title'],
            'submit_label' => $options['submit_label'],
            'cancel_url'   => $options['cancel_url'],
            'error'        => $error,
            'danger'       => $danger,
            'layout'       => $options['layout'],
        ];
        $template = $this->dialogs->template(self::PAGE_TEMPLATE, self::FRAGMENT_TEMPLATE, $request);
        if (!$this->twig instanceof Environment) {
            throw new LogicException('TimesheetDialogResponder needs Twig (symfony/twig-bundle).');
        }
        $response = new Response($this->twig->render($template, $context), $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $this->dialogs->fragment($response);
    }

    /**
     * A bundle message (its key without a translator).
     */
    private function trans(string $key): string
    {
        return $this->translator?->trans($key, [], 'NowoTimeTrackBundle') ?? $key;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{layout: string|null, title: string, submit_label: string, cancel_url: string, flash: string|null}
     */
    private function options(array $options, string $title, string $submit, string $successUrl): array
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['layout' => null, 'title' => null, 'submit_label' => null, 'cancel_url' => $successUrl, 'flash' => null]);
        foreach (['layout', 'title', 'submit_label', 'flash'] as $name) {
            $resolver->setAllowedTypes($name, ['null', 'string']);
        }
        $resolver->setAllowedTypes('cancel_url', 'string');
        /** @var array{layout: string|null, title: string|null, submit_label: string|null, cancel_url: string, flash: string|null} $resolved */
        $resolved = $resolver->resolve($options);
        $resolved['title'] ??= $this->trans($title);
        $resolved['submit_label'] ??= $this->trans($submit);

        return $resolved;
    }
}

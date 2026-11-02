<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Exception\DeviceCodeAttemptsExceededException;
use Nowo\TimeTrackBundle\Form\DeviceDecisionType;
use Nowo\TimeTrackBundle\Form\DeviceUserCodeType;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Ui\TimeTrackPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

/**
 * Optional default device verification page (contract v0.2 §2/§6; route `client_api.device_flow.default_page`, on by
 * default when the device flow is enabled; hosts with their own page, like Beacon, turn it off and call
 * {@see DeviceAuthorizationService} themselves). Session-authenticated (behind the host firewall), signed-in user
 * only: enter the user code → see client name, type and scopes → approve or deny (CSRF bound to action and request).
 */
final class TimeTrackDeviceVerificationController extends AbstractController
{
    public const TEMPLATE_CODE = '@NowoTimeTrackBundle/time_track/device_code.html.twig';

    public const TEMPLATE_CONFIRM = '@NowoTimeTrackBundle/time_track/device_confirm.html.twig';

    /**
     * @param bool $allowUnauthenticated Demo-only bypass of the access checker (the page still needs a signed-in user)
     */
    public function __construct(
        private readonly DeviceAuthorizationService $deviceService,
        private readonly TimeTrackAccessCheckerInterface $accessChecker,
        private readonly TimeTrackPages $pages,
        private readonly ?TranslatorInterface $translator = null,
        private readonly bool $allowUnauthenticated = false,
    ) {
    }

    /**
     * GET: code form (prefilled from `?code=`); POST: code lookup, or approve / deny of a request.
     */
    public function verify(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || (!$this->allowUnauthenticated && !$this->accessChecker->canAccess($user))) {
            throw new AccessDeniedException('Sign in to connect a device.');
        }

        if ($request->isMethod('POST') && $request->request->has('decision')) {
            return $this->decide($request, $user);
        }

        $form = $this->factory()->create(DeviceUserCodeType::class, ['user_code' => $request->query->getString('code')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{user_code: string} $data */
            $data = $form->getData();

            try {
                $authorization = $this->deviceService->findPending($data['user_code'], $user);
            } catch (DeviceCodeAttemptsExceededException) {
                $authorization = null;
                $form->get('user_code')->addError(new FormError($this->trans('device.too_many_attempts')));
            }

            if ($authorization instanceof DeviceAuthorization) {
                return $this->confirm($authorization);
            }

            if ($form->get('user_code')->getErrors()->count() === 0) {
                $form->get('user_code')->addError(new FormError($this->trans('device.invalid_code')));
            }
        }

        return $this->render(self::TEMPLATE_CODE, ['page' => $this->pages->deviceCodePage($form->createView())] + $this->pages->layoutContext());
    }

    private function decide(Request $request, UserInterface $user): Response
    {
        $decision      = $request->request->getString('decision');
        $authorization = $this->deviceService->findPendingById($request->request->getString('request'));
        if (!in_array($decision, ['approve', 'deny'], true) || !$authorization instanceof DeviceAuthorization) {
            return $this->result('device.expired', 'warning');
        }

        $form = $this->decisionForm($authorization, $decision);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

        if ($decision === 'approve') {
            $this->deviceService->approve($authorization, $user, $request->getClientIp());

            return $this->result('device.approved', 'success');
        }

        $this->deviceService->deny($authorization, $user, $request->getClientIp());

        return $this->result('device.denied', 'info');
    }

    private function confirm(DeviceAuthorization $authorization): Response
    {
        $approve = $this->decisionForm($authorization, 'approve')->createView();
        $deny    = $this->decisionForm($authorization, 'deny')->createView();

        return $this->render(self::TEMPLATE_CONFIRM, ['page' => $this->pages->deviceConfirmPage($authorization, $approve, $deny)] + $this->pages->layoutContext());
    }

    private function result(string $messageKey, string $tone): Response
    {
        return $this->render(self::TEMPLATE_CONFIRM, ['page' => $this->pages->deviceResultPage($messageKey, $tone)] + $this->pages->layoutContext());
    }

    /**
     * @return FormInterface<mixed>
     */
    private function decisionForm(DeviceAuthorization $authorization, string $decision): FormInterface
    {
        return $this->factory()->createNamed('', DeviceDecisionType::class, ['decision' => $decision, 'request' => $authorization->getId()], [
            'csrf_token_id' => $this->deviceService->csrfTokenId($decision, $authorization),
            'id_suffix'     => $decision,
        ]);
    }

    private function factory(): FormFactoryInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->container->get('form.factory');

        return $factory;
    }

    private function trans(string $key): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, [], 'NowoTimeTrackBundle') : $key;
    }
}

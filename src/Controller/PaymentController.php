<?php

namespace App\Controller;

use App\Service\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;

class PaymentController extends AbstractController
{
    public function __construct(
        private StripeService $stripeService,
        private MailerInterface $mailer
    ) {}

    #[Route('/payment', name: 'payment_checkout', methods: ['GET'])]
    public function checkout(): Response
    {
        return $this->render('payment/checkout.html.twig', [
            'stripe_public_key' => $this->getParameter('stripe_publishable_key'),
            'amount' => 20.00,
        ]);
    }

    #[Route('/payment/create-intent', name: 'payment_create_intent', methods: ['POST'])]
    public function createIntent(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return new JsonResponse(['error' => 'Email is required'], 400);
        }

        $paymentIntent = $this->stripeService->createPaymentIntent(
            amount: 20.00,
            currency: 'eur',
            metadata: [
                'customer_email' => $email,
            ]
        );

        return new JsonResponse([
            'client_secret' => $paymentIntent->client_secret,
        ]);
    }

    #[Route('/payment/webhook', name: 'payment_webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');

        if (!$sigHeader) {
            return new Response('Missing Stripe-Signature header', 400);
        }

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new Response('Invalid signature', 400);
        }

        if ($event->type === 'payment_intent.succeeded') {
            $paymentIntent = $event->data->object;
            $customerEmail = $paymentIntent->metadata->customer_email ?? null;

            if ($customerEmail) {
                $amount = number_format($paymentIntent->amount / 100, 2, ',', '.');

                $email = (new Email())
                    ->from('botiga@example.com')
                    ->to($customerEmail)
                    ->subject('Gràcies per la teva compra!')
                    ->html(
                        '<h1>Gràcies per la teva compra!</h1>' .
                        '<p>El teu pagament de <strong>' . $amount . ' €</strong> s\'ha processat correctament.</p>' .
                        '<p>Referència de pagament: ' . $paymentIntent->id . '</p>' .
                        '<p>Gràcies per confiar en nosaltres!</p>'
                    );

                $this->mailer->send($email);
            }
        }

        return new Response('', 200);
    }
}

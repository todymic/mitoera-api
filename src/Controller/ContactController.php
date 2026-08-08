<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/api/contact', name: 'contact', methods: ['POST'])]
    public function __invoke(Request $request, MailerInterface $mailer): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $token     = $data['recaptcha'] ?? '';
        $name      = trim($data['name'] ?? '');
        $email     = trim($data['email'] ?? '');
        $subject   = trim($data['subject'] ?? 'other');
        $message   = trim($data['message'] ?? '');
        $secret    = $_ENV['RECAPTCHA_SECRET'] ?? '';

        if ($name === '' || $email === '' || $message === '') {
            return $this->json(['error' => 'Champs requis manquants.'], 400);
        }

        // Vérification reCAPTCHA v3
        if ($secret !== '' && $token !== '') {
            $response = file_get_contents(
                'https://www.google.com/recaptcha/api/siteverify?secret='
                . urlencode($secret) . '&response=' . urlencode($token)
            );
            $result = json_decode($response, true);
            if (!($result['success'] ?? false) || ($result['score'] ?? 0) < 0.4) {
                return $this->json(['error' => 'Vérification anti-spam échouée.'], 403);
            }
        }

        $subjects = [
            'demo'    => 'Demande de démo',
            'pricing' => 'Question sur les tarifs',
            'support' => 'Support technique',
            'other'   => 'Autre demande',
        ];
        $subjectLabel = $subjects[$subject] ?? 'Contact';

        $mail = (new Email())
            ->from('noreply@mitoera.com')
            ->to('contact@irytech.net')
            ->replyTo($email)
            ->subject("[Mitoera] $subjectLabel — $name")
            ->text("Nom : $name\nEmail : $email\nSujet : $subjectLabel\n\n$message");

        $mailer->send($mail);

        return $this->json(['success' => true]);
    }
}

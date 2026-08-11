<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/api/contact', name: 'contact', methods: ['POST'])]
    public function __invoke(Request $request, MailerInterface $mailer): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $token   = $data['recaptcha'] ?? '';
        $name    = trim($data['name'] ?? '');
        $email   = trim($data['email'] ?? '');
        $subject = trim($data['subject'] ?? 'other');
        $message = trim($data['message'] ?? '');
        $secret  = $_ENV['RECAPTCHA_SECRET'] ?? '';

        if ($name === '' || $email === '' || $message === '') {
            return $this->json(['error' => 'Champs requis manquants.'], 400);
        }

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

        $mail = new TemplatedEmail()
            ->from('noreply@mitoera.com')
            ->to('contact@mitoera.com')
            ->replyTo($email)
            ->subject("[Mitoera] $subjectLabel — $name")
            ->htmlTemplate('emails/contact.html.twig')
            ->context([
                'senderName'    => $name,
                'senderEmail'   => $email,
                'subjectLabel'  => $subjectLabel,
                'messageBody'   => $message,
            ]);

        try {
            $mailer->send($mail);
        } catch (TransportExceptionInterface) {
            return $this->json(['error' => 'Erreur d\'envoi, veuillez réessayer.'], 500);
        }

        return $this->json(['success' => true]);
    }
}

<?php

namespace RSCD\Model;

use \PHPMailer\PHPMailer\PHPMailer;
use \PHPMailer\PHPMailer\Exception;
use \RSCD\Util\Strings;

/**
 * Wraps PHPMailer to send HTML email via SMTP.
 *
 * Supports To, CC, BCC, Reply-To, file/string attachments, and a template
 * system where `.tpl` files under `app/email/` are loaded, nested subtemplates
 * are recursively injected, and `%%key%%` placeholders are replaced with
 * caller-supplied variables.
 */
class Email {
    protected $smtp;
    protected $from;
    protected $replyTo;
    protected $to;
    protected $cc;
    protected $bcc;
    protected $subject;
    protected $body;

    /**
     * Initialise the email from an SMTP config object and an optional email data object.
     *
     * @param object     $smtp        SMTP configuration (host, port, user, pass, security).
     * @param object|null $email      Email data object with optional from, replyTo, to, cc,
     *                                bcc, subject, body, and attachments properties.
     * @param array      $attachments Legacy attachments array (deprecated; use $email->attachments).
     */
    public function __construct($smtp, $email = null, $attachments = []) {
        $this->smtp = $smtp;
        $this->from = ! empty($email->from) ? $email->from : '';
        $this->replyTo = ! empty($email->replyTo) ? $email->replyTo : '';
        $this->to = ! empty($email->to) ? $email->to : [];
        $this->cc = ! empty($email->cc) ? $email->cc : [];
        $this->bcc = ! empty($email->bcc) ? $email->bcc : [];
        $this->subject = ! empty($email->subject) ? $email->subject : '';
        $this->body = ! empty($email->body) ? $email->body : '';
        $this->attachments = ! empty($email->attachments) ? $email->attachments : [];
    }

    /**
     * Build a PHPMailer message from the stored properties and send it.
     *
     * Sets CharSet to UTF-8 so subjects containing non-ASCII characters
     * (e.g. em dashes) are encoded correctly per RFC 2047 rather than
     * being garbled as ISO-8859-1.
     *
     * Returns true on success, or a structured error object on failure. A
     * generic fallback error object is returned when send() itself fails
     * without throwing an exception.
     *
     * @return bool|object True on success; error object on failure.
     */
    public function send() {
        $smtp = $this->smtp;
        $mail = new PHPMailer(true);
        if(empty($this->replyTo)) {
            $this->replyTo = $this->from;
        }
        try {
            $mail->isSMTP();
            $mail->Host = $smtp->host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp->user;
            $mail->Password = $smtp->pass;
            $mail->SMTPSecure = $smtp->security;
            $mail->Port = $smtp->port;
            if(is_array($this->from) && count($this->from) == 2) {
                $mail->setFrom($this->from[0], $this->from[1]);
            } else {
                $mail->setFrom($this->from);
            }
            if(is_array($this->replyTo) && count($this->replyTo) == 2) {
                $mail->addReplyTo($this->replyTo[0], $this->replyTo[1]);
            } else {
                $mail->addReplyTo($this->replyTo);
            }
            foreach($this->to as $recipient) {
                if(is_array($recipient) && count($recipient) == 2) {
                    $mail->addAddress($recipient[0], $recipient[1]);
                } else {
                    $mail->addAddress($recipient);
                }
            }
            foreach($this->cc as $recipient) {
                if(is_array($recipient) && count($recipient) == 2) {
                    $mail->addCC($recipient[0], $recipient[1]);
                } else {
                    $mail->addCC($recipient);
                }
            }
            foreach($this->bcc as $recipient) {
                if(is_array($recipient) && count($recipient) == 2) {
                    $mail->addBCC($recipient[0], $recipient[1]);
                } else {
                    $mail->addBCC($recipient);
                }
            }
            foreach($this->attachments as $attachment) {
                $mail->addStringAttachment($attachment['content'], $attachment['name']);
            }

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $this->subject;
            $mail->Body    = $this->body;
            $mail->AltBody = Strings::getTextFromHtml($this->body);
            return $mail->send();
        } catch(Exception $e) {
            return (object)[
                'errors' => [
                    (object)[
                        'type' => 'exception',
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
                    ]
                ]
            ];
        }
        return (object)[
            'errors' => [
                (object)[
                    'type' => 'generic',
                    'code' => 9000,
                    'message' => 'An unknown error occurred, please try again later!  If problems persist contact support.'
                ]
            ]
        ];
    }

    /**
     * Load an email template file and interpolate variables into the body.
     *
     * Nested `{{subtemplate}}` blocks are resolved recursively before
     * `%%key%%` variable substitution is applied.
     *
     * @param  string $template  Template name (without .tpl extension).
     * @param  array  $variables Associative array of placeholder key => replacement value.
     * @return bool True if the template file was found and loaded; false otherwise.
     */
    public function setBodyFromTemplate($template, $variables = []) {
        $path = __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . $template . '.tpl';
        if(! file_exists($path)) {
            return false;
        }
        $this->body = file_get_contents($path);
        //load subtemplates
        $subtemplates = [];
        if(preg_match('/{\{(.+?)\}\}/i', $this->body, $subtemplates) == 1) {
            unset($subtemplates[0]);
            foreach($subtemplates as $subtemplate) {
                $subbody = $this->injectSubTemplateIntoString($subtemplate, $variables);
                // Plain replacement, same reasoning as Strings::inject -- a value
                // containing $0 or \1 must land in the body verbatim, not as a
                // backreference.
                $this->body = str_ireplace('{{' . $subtemplate . '}}', $subbody, $this->body);
            }
        }
        foreach($variables as $key => $value) {
            $this->body = str_ireplace('%%' . $key . '%%', $value, $this->body);
        }
        return true;
    }

    /**
     * Load a subtemplate file, resolve nested subtemplates, and interpolate variables.
     *
     * @param  string $subtemplate Subtemplate name (without .tpl extension).
     * @param  array  $variables   Associative array of placeholder key => replacement value.
     * @return string The rendered subtemplate content, or empty string if file not found.
     */
    protected function injectSubTemplateIntoString($subtemplate, $variables = []) {
        $path = __ROOTS__ . 'app' . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . $subtemplate . '.tpl';
        if(! file_exists($path)) {
            return '';
        }
        $subbody = file_get_contents($path);
        $subtemplates = [];
        if(preg_match('/{\{(.+?)\}\}/i', $subbody, $subtemplates) == 1) {
            unset($subtemplates[0]);
            foreach($subtemplates as $subsubtemplate) {
                $subsubbody = $this->injectSubTemplateIntoString($subsubtemplate, $variables);
                $subbody = str_ireplace('{{' . $subsubtemplate . '}}', $subsubbody, $subbody);
            }
        }
        foreach($variables as $key => $value) {
            $subbody = str_ireplace('%%' . $key . '%%', $value, $subbody);
        }
        return $subbody;
    }

    /**
     * Return the from address.
     *
     * @return string|array
     */
    public function getFrom() {
        return $this->from;
    }

    /**
     * Return the reply-to address.
     *
     * @return string|array
     */
    public function getReplyTo() {
        return $this->replyTo;
    }

    /**
     * Return the to recipients list.
     *
     * @return array
     */
    public function getTo() {
        return $this->to;
    }

    /**
     * Return the CC recipients list.
     *
     * @return array
     */
    public function getCc() {
        return $this->cc;
    }

    /**
     * Return the BCC recipients list.
     *
     * @return array
     */
    public function getBcc() {
        return $this->bcc;
    }

    /**
     * Return the email subject line.
     *
     * @return string
     */
    public function getSubject() {
        return $this->subject;
    }

    /**
     * Return the email body HTML.
     *
     * @return string
     */
    public function getBody() {
        return $this->body;
    }

    /**
     * Set the from address.
     *
     * @param  string|array $from From address or [address, name] pair.
     * @return void
     */
    public function setFrom($from) {
        $this->from = $from;
    }

    /**
     * Set the reply-to address.
     *
     * @param  string|array $replyTo Reply-to address or [address, name] pair.
     * @return void
     */
    public function setReplyTo($replyTo) {
        $this->replyTo = $replyTo;
    }

    /**
     * Set the to recipients list.
     *
     * @param  array $to Array of email addresses or [address, name] pairs.
     * @return void
     */
    public function setTo($to) {
        $this->to = $to;
    }

    /**
     * Set the CC recipients list.
     *
     * @param  array $cc Array of email addresses or [address, name] pairs.
     * @return void
     */
    public function setCc($cc) {
        $this->cc = $cc;
    }

    /**
     * Set the BCC recipients list.
     *
     * @param  array $bcc Array of email addresses or [address, name] pairs.
     * @return void
     */
    public function setBcc($bcc) {
        $this->bcc = $bcc;
    }

    /**
     * Set the email subject line.
     *
     * @param  string $subject The subject text.
     * @return void
     */
    public function setSubject($subject) {
        $this->subject = $subject;
    }

    /**
     * Set the email body HTML directly.
     *
     * @param  string $body The HTML body content.
     * @return void
     */
    public function setBody($body) {
        $this->body = $body;
    }
}

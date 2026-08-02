@extends('layouts.legal', [
    'title' => 'Privacy Policy',
    'description' => 'Privacy information for users of the Maik Cat catalytic converter catalog and valuation application.',
    'lang' => 'en',
    'dir' => 'ltr',
    'alternateUrl' => route('legal.privacy.ar'),
    'alternateLabel' => 'العربية',
])

@section('content')
    <span class="eyebrow">Maik Cat Legal</span>
    <h1>Privacy Policy</h1>
    <p class="updated">Effective date: {{ config('legal.effective_date') }}</p>

    <p>This Privacy Policy explains how {{ config('legal.operator_name', 'Maik Cat') }} (“Maik Cat”, “we”, “us”, or “our”) collects, uses, stores, and shares information when you use the Maik Cat mobile application, website, APIs, catalog, calculator, notifications, administration tools, and related services (the “Service”).</p>

    <h2>1. Information we collect</h2>

    <h3>Account and profile information</h3>
    <ul>
        <li>Name and email address.</li>
        <li>Password in securely hashed form. We do not store your readable password.</li>
        <li>Preferred application language and account status.</li>
        <li>Authentication tokens and password-reset records needed to sign you in and secure your account.</li>
    </ul>

    <h3>Saved content and preferences</h3>
    <ul>
        <li>Catalytic converter items you save to your account.</li>
        <li>Notification preferences, notification delivery records, and read/unread status.</li>
        <li>Firebase Cloud Messaging or similar device tokens when push notifications are enabled.</li>
    </ul>

    <h3>Catalog, calculation, and import data</h3>
    <ul>
        <li>Search terms, filters, item codes, viewed items, and calculator inputs that are sent to the Service.</li>
        <li>For authorized import users: uploaded spreadsheet files, file metadata, imported records, duplicate-review information, validation issues, source references, and images.</li>
        <li>Administrative actions relating to items, car groups, metal prices, notifications, users, and imports.</li>
    </ul>

    <h3>Technical and usage information</h3>
    <ul>
        <li>IP address, request time, endpoint, response status, device or browser information, application version, language, and operating system where available.</li>
        <li>Crash, security, diagnostic, and performance logs.</li>
        <li>Cookies or session identifiers used by the website and administration dashboard.</li>
    </ul>

    <p>We do not intentionally request precise location, contact lists, bank-card details, or government identification through the current Service.</p>

    <h2>2. How we use information</h2>
    <p>We use information to:</p>
    <ul>
        <li>Authenticate users, maintain accounts, reset passwords, and prevent unauthorized access.</li>
        <li>Provide catalog search, item details, related items, calculator estimates, saved items, market information, and notifications.</li>
        <li>Process authorized spreadsheet imports, validate records, detect duplicates, associate images, and maintain catalog quality.</li>
        <li>Send operational, security, market-change, and account notifications.</li>
        <li>Maintain, troubleshoot, secure, monitor, and improve the Service.</li>
        <li>Investigate misuse, fraud, security incidents, or violations of our Terms.</li>
        <li>Comply with legal obligations and respond to lawful requests.</li>
    </ul>

    <h2>3. Calculator and catalog information</h2>
    <p>Calculator inputs may be processed to return an estimate based on metal prices and selected rates. Catalog and calculator information is not used to make automated decisions that produce legal or similarly significant effects about you.</p>
    <p>Do not include personal or confidential information in serial-code fields, notes, spreadsheets, filenames, images, or other catalog submissions.</p>

    <h2>4. How we share information</h2>
    <p>We may share limited information with service providers that help us operate the Service, including:</p>
    <ul>
        <li>Cloud hosting, database, storage, backup, email, security, and monitoring providers.</li>
        <li>Push-notification providers such as Firebase Cloud Messaging, which may receive a device token and notification-delivery information.</li>
        <li>Market-data providers used to retrieve current or historical precious-metal prices. We do not need to send them your account profile for ordinary market-price retrieval.</li>
        <li>Image-processing or artificial-intelligence providers used by authorized administrators to clean or prepare catalog images. Personal data should not be placed in such images.</li>
        <li>Professional advisers, auditors, authorities, or courts when disclosure is required or reasonably necessary to protect rights, safety, and legal interests.</li>
    </ul>
    <p>We do not sell personal information or share it for third-party behavioral advertising.</p>

    <h2>5. Legal grounds</h2>
    <p>Depending on the applicable law, we process information because it is necessary to provide the Service, comply with legal duties, protect legitimate interests such as security and product improvement, or because you have given consent, such as enabling push notifications.</p>

    <h2>6. Data retention</h2>
    <p>We retain account data while the account is active and for a reasonable period afterward where needed for security, backup, dispute resolution, audit, or legal obligations. Saved items remain until removed or the related account is deleted.</p>
    <p>Import files, issue records, duplicate-review records, catalog sources, administrative logs, and backups may be kept for longer periods because they support catalog integrity, traceability, and recovery. Retention periods may vary according to operational and legal needs.</p>

    <h2>7. International processing</h2>
    <p>Our service providers may process information in countries other than your own. Where required, we use reasonable contractual, organizational, or technical safeguards for such transfers.</p>

    <h2>8. Security</h2>
    <p>We use reasonable technical and organizational safeguards, including access controls, password hashing, authenticated APIs, logging, and protected administration functions. No system is completely secure, and we cannot guarantee that unauthorized access, loss, or misuse will never occur.</p>

    <h2>9. Your choices and rights</h2>
    <p>Subject to applicable law, you may:</p>
    <ul>
        <li>View and update available profile information through the application.</li>
        <li>Remove saved items.</li>
        <li>Disable push notifications through your device settings.</li>
        <li>Request access, correction, deletion, restriction, or a copy of personal data.</li>
        <li>Object to certain processing or withdraw consent where processing is based on consent.</li>
    </ul>
    <p>Some information may need to be retained for security, audit, legal, or catalog-integrity purposes. To request account or data deletion, use the support contact published in the application or below.</p>

    <h2>10. Children</h2>
    <p>The Service is intended for professional or adult users and is not directed to children. We do not knowingly collect personal information from children. Contact us if you believe a child has provided personal information.</p>

    <h2>11. Third-party links</h2>
    <p>The Service may display links or source references controlled by third parties. Their privacy practices are independent from ours, and you should review their policies before providing information to them.</p>

    <h2>12. Policy changes</h2>
    <p>We may update this Privacy Policy to reflect changes in the Service, law, or data practices. The effective date at the top will be updated. Material changes may also be communicated through the Service.</p>

    <div class="contact-card">
        <h2>Contact and privacy requests</h2>
        <p>Use the support contact published in the application for privacy questions or data requests.</p>
        @if(config('legal.support_email'))
            <p>Email: <a href="mailto:{{ config('legal.support_email') }}">{{ config('legal.support_email') }}</a></p>
        @endif
        @if(config('legal.support_phone'))
            <p>Phone: {{ config('legal.support_phone') }}</p>
        @endif
        @if(config('legal.website_url'))
            <p>Website: <a href="{{ config('legal.website_url') }}">{{ config('legal.website_url') }}</a></p>
        @endif
    </div>
@endsection

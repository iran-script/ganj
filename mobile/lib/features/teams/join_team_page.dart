import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import 'team_repository.dart';

class JoinTeamPage extends StatefulWidget {
  const JoinTeamPage({required this.auth, this.initialInviteCode, this.autoScan = false, super.key});
  final AuthController auth;
  final String? initialInviteCode;
  final bool autoScan;

  @override
  State<JoinTeamPage> createState() => _JoinTeamPageState();
}

class _JoinTeamPageState extends State<JoinTeamPage> {
  final formKey = GlobalKey<FormState>();
  final inviteCode = TextEditingController();
  late final TeamRepository repository = TeamRepository(widget.auth.api);
  Map<String, dynamic>? preview;
  bool loading = false;
  bool submitting = false;
  String? error;

  @override
  void initState() {
    super.initState();
    final initial = widget.initialInviteCode;
    if (initial != null) {
      inviteCode.text = initial;
      WidgetsBinding.instance.addPostFrameCallback((_) => checkInvite());
    } else if (widget.autoScan) {
      WidgetsBinding.instance.addPostFrameCallback((_) => scan());
    }
  }

  @override
  void dispose() {
    inviteCode.dispose();
    super.dispose();
  }

  Future<void> checkInvite() async {
    final code = extractInviteCode(inviteCode.text);
    if (code == null) {
      setState(() {
        preview = null;
        error = 'کد دعوت معتبر نیست. کد را بررسی و دوباره وارد کنید.';
      });
      return;
    }
    setState(() { loading = true; error = null; preview = null; });
    try {
      preview = await repository.previewInvite(code);
    } catch (failure) {
      error = inviteError(failure);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> scan() async {
    final code = await Navigator.of(context).push<String>(MaterialPageRoute(
      builder: (_) => const _InviteScannerPage(),
    ));
    if (code != null && mounted) {
      inviteCode.text = code;
      await checkInvite();
    }
  }

  Future<void> paste() async {
    final value = await Clipboard.getData(Clipboard.kTextPlain);
    if (!mounted || value?.text == null) return;
    inviteCode.text = value!.text!.trim();
    await checkInvite();
  }

  Future<void> requestJoin() async {
    if (submitting || preview == null) return;
    setState(() { submitting = true; error = null; });
    try {
      final result = await repository.join(extractInviteCode(inviteCode.text)!);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text('درخواست پیوستن به تیم «${result.teamName}» برای تأیید کاپیتان ارسال شد.'),
        ));
        Navigator.of(context).pop(true);
      }
    } catch (failure) {
      if (mounted) setState(() => error = inviteError(failure));
    } finally {
      if (mounted) setState(() => submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('پیوستن به تیم')),
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: ListView(
                padding: const EdgeInsets.all(NexoSpace.x4),
                children: [
                  const NexoCityHeader(eyebrow: 'دعوت تیمی', title: 'دعوت را بررسی کن', body: 'پیش از ارسال درخواست، نام تیم و وضعیت ظرفیت را ببین.'),
                  Row(children: [
                    Expanded(child: OutlinedButton.icon(onPressed: scan, icon: const Icon(Icons.qr_code_scanner), label: const Text('اسکن QR'))),
                    const SizedBox(width: 10),
                    Expanded(child: OutlinedButton.icon(onPressed: paste, icon: const Icon(Icons.content_paste), label: const Text('Paste کد'))),
                  ]),
                  const SizedBox(height: 14),
                  Form(
                    key: formKey,
                    child: TextFormField(
                      controller: inviteCode,
                      textDirection: TextDirection.ltr,
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => checkInvite(),
                      decoration: const InputDecoration(labelText: 'کد یا لینک دعوت', prefixIcon: Icon(Icons.key_outlined)),
                    ),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(onPressed: loading ? null : checkInvite, icon: loading ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.search), label: const Text('بررسی دعوت')),
                  if (error != null) ...[
                    const SizedBox(height: 12),
                    NexoNotice(error!, icon: Icons.error_outline, color: NexoTheme.danger),
                  ],
                  if (preview case final data?) ...[
                    const SizedBox(height: 14),
                    Card(child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        Text((data['team'] as Map<String, dynamic>)['name'] as String? ?? 'تیم', style: Theme.of(context).textTheme.titleLarge),
                        const SizedBox(height: 8),
                        Text('کاپیتان: ${((data['team'] as Map<String, dynamic>)['captain'] as Map<String, dynamic>)['name'] ?? '—'}'),
                        Text('ظرفیت: ${data['member_count']} از ${data['capacity']} عضو'),
                        if (data['expires_at'] != null) Text('انقضا: ${data['expires_at']}'),
                        const SizedBox(height: 14),
                        FilledButton.icon(onPressed: submitting ? null : requestJoin, icon: submitting ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send_outlined), label: const Text('درخواست پیوستن')),
                        const SizedBox(height: 6),
                        const Text('عضویت فقط پس از تأیید کاپیتان ثبت می‌شود.', textAlign: TextAlign.center, style: TextStyle(color: NexoTheme.muted)),
                      ]),
                    )),
                  ],
                ],
              ),
            ),
          ),
        ),
      );
}

String? extractInviteCode(String input) {
  final trimmed = input.trim();
  if (RegExp(r'^[a-zA-Z0-9_-]{16,64}$').hasMatch(trimmed)) return trimmed;
  final uri = Uri.tryParse(trimmed);
  if (uri == null) return null;
  final isDeepLinkRoute = uri.scheme.isEmpty && uri.pathSegments.firstOrNull == 'invite';
  if (!isDeepLinkRoute && !['nexo', 'https'].contains(uri.scheme)) return null;
  final candidate = uri.pathSegments.isEmpty ? uri.queryParameters['code'] : uri.pathSegments.last;
  return candidate != null && RegExp(r'^[a-zA-Z0-9_-]{16,64}$').hasMatch(candidate) ? candidate : null;
}

String inviteError(Object error) {
  if (ApiClient.isNetworkFailure(error)) return 'به سرور وصل نشدیم. اتصال اینترنت را بررسی کنید.';
  final code = error is ApiError ? error.code : '';
  return switch (code) {
    'INVITE_EXPIRED' => 'این دعوت منقضی شده است.',
    'INVITE_REVOKED' => 'این دعوت لغو شده است.',
    'INVITE_EXHAUSTED' => 'ظرفیت این دعوت تکمیل شده است.',
    'TEAM_FULL' => 'ظرفیت تیم تکمیل است.',
    'TEAM_LOCKED' => 'ترکیب این تیم به‌دلیل رزرو یا بازی فعال قفل است.',
    'STATE_TRANSITION_INVALID' => 'این تیم قفل یا بایگانی شده و درخواست نمی‌پذیرد.',
    'ALREADY_TEAM_MEMBER' => 'شما هم‌اکنون عضو این تیم هستید.',
    'CONFLICT' => 'برای این دعوت درخواست تکراری ثبت شده است.',
    'RESOURCE_NOT_FOUND' => 'کد دعوت معتبر نیست.',
    _ => error is ApiError ? error.userMessage : 'بررسی دعوت انجام نشد.',
  };
}

class _InviteScannerPage extends StatelessWidget {
  const _InviteScannerPage();

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('اسکن دعوت تیم')),
        body: MobileScanner(onDetect: (capture) {
          final raw = capture.barcodes.firstOrNull?.rawValue;
          final code = raw == null ? null : extractInviteCode(raw);
          if (code != null && Navigator.of(context).canPop()) Navigator.of(context).pop(code);
        }),
      );
}

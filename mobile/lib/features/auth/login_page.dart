import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../../core/auth/user_profile.dart';
import '../../app/nexo_routes.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final formKey = GlobalKey<FormState>();
  final identifier = TextEditingController();
  final password = TextEditingController();
  String? error;
  bool submitting = false;

  @override
  void dispose() {
    identifier.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (submitting || !formKey.currentState!.validate()) {
      return;
    }
    setState(() {
      submitting = true;
      error = null;
    });
    try {
      await widget.auth.login(
          UserProfile.normalizeDigits(identifier.text.trim()), password.text);
    } on ApiError catch (failure) {
      if (mounted) {
        setState(() => error = failure.userMessage);
      }
    } catch (_) {
      if (mounted) {
        setState(
            () => error = 'ورود انجام نشد. اتصال را بررسی و دوباره تلاش کنید.');
      }
    } finally {
      if (mounted) {
        setState(() => submitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        resizeToAvoidBottomInset: true,
        body: NexoPageBody(
          child: LayoutBuilder(builder: (context, constraints) {
            final compact = MediaQuery.sizeOf(context).height < 680;
            return SingleChildScrollView(
              keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
              padding: EdgeInsets.all(compact ? NexoSpace.x4 : NexoSpace.x6),
              child: Form(
                key: formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: NexoSpace.x4),
                    const NexoBrandMark(),
                    const SizedBox(height: NexoSpace.x3),
                    Text('ماجراجویی از همین‌جا شروع می‌شود',
                        style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: NexoSpace.x2),
                    const Text('برای ادامه وارد حساب بازیکن شوید.',
                        style: TextStyle(color: NexoTheme.muted)),
                    SizedBox(height: compact ? NexoSpace.x6 : 36),
                    TextFormField(
                      controller: identifier,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [
                        AutofillHints.username,
                        AutofillHints.email,
                      ],
                      decoration: const InputDecoration(
                        labelText: 'ایمیل یا شماره همراه',
                        prefixIcon: Icon(Icons.person_outline),
                      ),
                      validator: (value) =>
                          value == null || value.trim().isEmpty
                              ? 'ایمیل یا شماره همراه را وارد کنید.'
                              : null,
                    ),
                    const SizedBox(height: NexoSpace.x3),
                    TextFormField(
                      controller: password,
                      obscureText: true,
                      textInputAction: TextInputAction.done,
                      autofillHints: const [AutofillHints.password],
                      onFieldSubmitted: (_) => submit(),
                      decoration: const InputDecoration(
                        labelText: 'رمز عبور',
                        prefixIcon: Icon(Icons.lock_outline),
                      ),
                      validator: (value) => value == null || value.isEmpty
                          ? 'رمز عبور را وارد کنید.'
                          : null,
                    ),
                    if (error != null || widget.auth.error != null) ...[
                      const SizedBox(height: NexoSpace.x4),
                      NexoNotice(error ?? widget.auth.error!.userMessage,
                          icon: Icons.warning_amber_rounded,
                          color: NexoTheme.danger),
                    ],
                    const SizedBox(height: NexoSpace.x4),
                    FilledButton.icon(
                      onPressed: submitting ? null : submit,
                      icon: submitting
                          ? const SizedBox.square(
                              dimension: 20,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2, color: NexoTheme.background))
                          : const Icon(Icons.login),
                      label: Text(submitting ? 'در حال ورود…' : 'ورود به بازی'),
                    ),
                    const SizedBox(height: NexoSpace.x3),
                    OutlinedButton(
                      onPressed: submitting
                          ? null
                          : () => Navigator.of(context)
                              .pushNamed(NexoRoutes.register),
                      child: const Text('ساخت حساب بازیکن'),
                    ),
                    const SizedBox(height: NexoSpace.x2),
                    const Text('اطلاعات ورود شما به‌صورت امن نگهداری می‌شود.',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            color: NexoTheme.muted, fontSize: 12, height: 1.6)),
                  ],
                ),
              ),
            );
          }),
        ),
      );
}

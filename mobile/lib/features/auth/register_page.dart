import 'dart:convert';

import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_routes.dart';
import '../../app/nexo_theme.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../../core/auth/user_profile.dart';

class RegisterPage extends StatefulWidget {
  const RegisterPage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends State<RegisterPage> {
  final formKey = GlobalKey<FormState>();
  final name = TextEditingController();
  final email = TextEditingController();
  final mobile = TextEditingController();
  final password = TextEditingController();
  final confirmation = TextEditingController();
  String? error;
  bool submitting = false;

  @override
  void dispose() {
    name.dispose();
    email.dispose();
    mobile.dispose();
    password.dispose();
    confirmation.dispose();
    super.dispose();
  }

  String? validateEmail(String? value) {
    final input = value?.trim() ?? '';
    if (input.isEmpty) {
      return null;
    }
    return RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(input)
        ? null
        : 'قالب ایمیل معتبر نیست.';
  }

  String? validateMobile(String? value) {
    final input = value?.trim() ?? '';
    if (input.isEmpty) {
      return null;
    }
    return RegExp(r'^\+?[0-9]{7,15}$')
            .hasMatch(UserProfile.normalizeDigits(input))
        ? null
        : 'شماره همراه باید ۷ تا ۱۵ رقم داشته باشد.';
  }

  String? validateIdentifier(String? _) {
    if (email.text.trim().isEmpty && mobile.text.trim().isEmpty) {
      return 'ایمیل یا شماره همراه را وارد کنید.';
    }
    return null;
  }

  String? validatePassword(String? value) {
    final input = value ?? '';
    if (input.runes.length < 12) {
      return 'رمز عبور باید دست‌کم ۱۲ نویسه باشد.';
    }
    if (utf8.encode(input).length > 72) {
      return 'رمز عبور نباید بیش از ۷۲ بایت باشد.';
    }
    return null;
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
      await widget.auth.register(
        name: name.text,
        email: email.text,
        mobile: UserProfile.normalizeDigits(mobile.text.trim()),
        password: password.text,
      );
    } on ApiError catch (failure) {
      if (mounted) {
        setState(() => error = failure.fieldMessage('name') ??
            failure.fieldMessage('email') ??
            failure.fieldMessage('mobile') ??
            failure.fieldMessage('password') ??
            failure.userMessage);
      }
    } catch (_) {
      if (mounted) {
        setState(() =>
            error = 'ساخت حساب انجام نشد. اتصال را بررسی و دوباره تلاش کنید.');
      }
    } finally {
      if (mounted) {
        setState(() => submitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('ساخت حساب')),
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
                    const NexoBrandMark(compact: true),
                    const SizedBox(height: NexoSpace.x3),
                    Text('حساب بازیکن بسازید',
                        style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: NexoSpace.x2),
                    const Text('برای ثبت‌نام ایمیل یا شماره همراه کافی است.',
                        style: TextStyle(color: NexoTheme.muted)),
                    const SizedBox(height: NexoSpace.x6),
                    TextFormField(
                      controller: name,
                      textCapitalization: TextCapitalization.words,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.name],
                      decoration: const InputDecoration(
                          labelText: 'نام نمایشی',
                          prefixIcon: Icon(Icons.badge_outlined)),
                      validator: (value) =>
                          value == null || value.trim().isEmpty
                              ? 'نام را وارد کنید.'
                              : value.trim().length > 255
                                  ? 'نام نباید بیش از ۲۵۵ نویسه باشد.'
                                  : null,
                    ),
                    const SizedBox(height: NexoSpace.x3),
                    TextFormField(
                      controller: email,
                      keyboardType: TextInputType.emailAddress,
                      textDirection: TextDirection.ltr,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.email],
                      decoration: const InputDecoration(
                          labelText: 'ایمیل (اختیاری)',
                          prefixIcon: Icon(Icons.alternate_email)),
                      validator: (value) =>
                          validateIdentifier(value) ?? validateEmail(value),
                    ),
                    const SizedBox(height: NexoSpace.x3),
                    TextFormField(
                      controller: mobile,
                      keyboardType: TextInputType.phone,
                      textDirection: TextDirection.ltr,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.telephoneNumber],
                      decoration: const InputDecoration(
                          labelText: 'شماره همراه (اختیاری)',
                          prefixIcon: Icon(Icons.phone_outlined)),
                      validator: (value) =>
                          validateIdentifier(value) ?? validateMobile(value),
                    ),
                    const SizedBox(height: NexoSpace.x3),
                    TextFormField(
                      controller: password,
                      obscureText: true,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.newPassword],
                      decoration: const InputDecoration(
                          labelText: 'رمز عبور (حداقل ۱۲ نویسه)',
                          prefixIcon: Icon(Icons.lock_outline)),
                      validator: validatePassword,
                    ),
                    const SizedBox(height: NexoSpace.x3),
                    TextFormField(
                      controller: confirmation,
                      obscureText: true,
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => submit(),
                      decoration: const InputDecoration(
                          labelText: 'تکرار رمز عبور',
                          prefixIcon: Icon(Icons.lock_reset_outlined)),
                      validator: (value) => value != password.text
                          ? 'تکرار رمز عبور یکسان نیست.'
                          : null,
                    ),
                    if (error != null) ...[
                      const SizedBox(height: NexoSpace.x4),
                      NexoNotice(error!,
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
                          : const Icon(Icons.person_add_alt_1),
                      label:
                          Text(submitting ? 'در حال ساخت حساب…' : 'ساخت حساب'),
                    ),
                    TextButton(
                      onPressed: submitting
                          ? null
                          : () => Navigator.of(context)
                              .pushReplacementNamed(NexoRoutes.login),
                      child: const Text('از قبل حساب دارید؟ ورود'),
                    ),
                  ],
                ),
              ),
            );
          }),
        ),
      );
}

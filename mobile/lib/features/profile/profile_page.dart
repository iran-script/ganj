import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/auth/user_profile.dart';
import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';
import 'profile_repository.dart';

class ProfilePage extends StatefulWidget {
  const ProfilePage({required this.auth, this.repository, super.key});

  final AuthController auth;
  final ProfileRepository? repository;

  @override
  State<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<ProfilePage> {
  final formKey = GlobalKey<FormState>();
  late final ProfileRepository repository;
  late Future<UserProfile> profileFuture;
  final name = TextEditingController();
  final email = TextEditingController();
  final mobile = TextEditingController();
  UserProfile? profile;
  String? error;
  String? success;
  bool saving = false;

  @override
  void initState() {
    super.initState();
    repository = widget.repository ?? ProfileRepository(widget.auth.api);
    profileFuture = repository.load();
  }

  @override
  void dispose() {
    name.dispose();
    email.dispose();
    mobile.dispose();
    super.dispose();
  }

  void retryLoad() {
    setState(() {
      error = null;
      profileFuture = repository.load();
    });
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

  String? validateIdentifiers(String? _) =>
      email.text.trim().isEmpty && mobile.text.trim().isEmpty
          ? 'حداقل یک ایمیل یا شماره همراه باید ثبت شود.'
          : null;

  Future<void> save() async {
    if (saving || !formKey.currentState!.validate()) {
      return;
    }
    setState(() {
      saving = true;
      error = null;
      success = null;
    });
    try {
      final updated = await repository.update(
        name: name.text,
        email: email.text,
        mobile: UserProfile.normalizeDigits(mobile.text.trim()),
      );
      widget.auth.applyProfile(updated);
      if (mounted) {
        setState(() {
          profile = updated;
          success = 'پروفایل با موفقیت ذخیره شد.';
        });
      }
    } on ApiError catch (failure) {
      if (mounted) {
        setState(() => error = failure.fieldMessage('name') ??
            failure.fieldMessage('email') ??
            failure.fieldMessage('mobile') ??
            failure.userMessage);
      }
    } catch (_) {
      if (mounted) {
        setState(() => error = 'ذخیره پروفایل انجام نشد. دوباره تلاش کنید.');
      }
    } finally {
      if (mounted) {
        setState(() => saving = false);
      }
    }
  }

  Future<void> logout() async {
    if (await widget.auth.logout()) {
      return;
    }
    if (!mounted) {
      return;
    }
    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('اقدام‌های ذخیره‌شده'),
        content: const Text(
            'اقدام ارسال‌نشده دارید. پیش از خروج همگام‌سازی کنید یا حذف آن‌ها را تأیید کنید.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('بازگشت')),
          FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('حذف و خروج')),
        ],
      ),
    );
    if (discard == true) {
      await widget.auth.logout(discardPending: true);
    }
  }

  void _populate(UserProfile value) {
    if (profile != null) {
      return;
    }
    profile = value;
    name.text = value.name;
    email.text = value.email ?? '';
    mobile.text = value.mobile ?? '';
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('پروفایل من'),
          actions: [
            IconButton(
              tooltip: 'خروج از حساب',
              onPressed: logout,
              icon: const Icon(Icons.logout),
            ),
          ],
        ),
        body: NexoPageBody(
          child: FutureBuilder<UserProfile>(
            future: profileFuture,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const NexoLoading(message: 'دریافت پروفایل…');
              }
              if (snapshot.hasError && profile == null) {
                final failure = snapshot.error;
                final offline =
                    failure != null && ApiClient.isNetworkFailure(failure);
                return NexoErrorView(
                  message: offline
                      ? 'برای دریافت پروفایل به اینترنت وصل شوید.'
                      : failure is ApiError
                          ? failure.userMessage
                          : 'دریافت پروفایل ممکن نشد.',
                  offline: offline,
                  onRetry: retryLoad,
                );
              }
              final value = profile ?? snapshot.data;
              if (value == null) {
                return const NexoEmptyView(
                  title: 'پروفایلی پیدا نشد',
                  message: 'برای دریافت اطلاعات، دوباره تلاش کنید.',
                );
              }
              _populate(value);
              return SingleChildScrollView(
                keyboardDismissBehavior:
                    ScrollViewKeyboardDismissBehavior.onDrag,
                padding: const EdgeInsets.all(NexoSpace.x4),
                child: Form(
                  key: formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const NexoCityHeader(
                        eyebrow: 'حساب بازیکن',
                        title: 'پروفایل من',
                        body: 'اطلاعات حساب مستقیماً از سرویس NEXO بارگیری می‌شود.',
                      ),
                      Card(
                        child: Padding(
                          padding: const EdgeInsets.all(NexoSpace.x4),
                          child: Row(children: [
                            CircleAvatar(
                              radius: 30,
                              backgroundColor: NexoTheme.amber,
                              foregroundColor: NexoTheme.background,
                              child: Text(
                                value.name.trim().isEmpty
                                    ? 'N'
                                    : value.name.trim().substring(0, 1),
                                style: const TextStyle(
                                    fontSize: 24, fontWeight: FontWeight.w800),
                              ),
                            ),
                            const SizedBox(width: NexoSpace.x3),
                            Expanded(
                              child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(value.name,
                                        style: Theme.of(context)
                                            .textTheme
                                            .titleLarge),
                                    const SizedBox(height: NexoSpace.x1),
                                    Text(
                                      'وضعیت حساب: ${_status(value.status)}',
                                      style: const TextStyle(
                                          color: NexoTheme.muted),
                                    ),
                                    const SizedBox(height: NexoSpace.x1),
                                    Text(
                                      'نقش: ${value.roles.map(_role).join('، ')}',
                                      style: const TextStyle(
                                          color: NexoTheme.muted),
                                    ),
                                  ]),
                            ),
                          ]),
                        ),
                      ),
                      const SizedBox(height: NexoSpace.x3),
                      Text('اطلاعات قابل‌ویرایش',
                          style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: NexoSpace.x3),
                      TextFormField(
                        controller: name,
                        textCapitalization: TextCapitalization.words,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(
                          labelText: 'نام نمایشی',
                          prefixIcon: Icon(Icons.badge_outlined),
                        ),
                        validator: (input) =>
                            input == null || input.trim().isEmpty
                                ? 'نام را وارد کنید.'
                                : input.trim().length > 255
                                    ? 'نام نباید بیش از ۲۵۵ نویسه باشد.'
                                    : null,
                      ),
                      const SizedBox(height: NexoSpace.x3),
                      TextFormField(
                        controller: email,
                        keyboardType: TextInputType.emailAddress,
                        textDirection: TextDirection.ltr,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(
                          labelText: 'ایمیل',
                          prefixIcon: Icon(Icons.alternate_email),
                        ),
                        validator: (input) =>
                            validateIdentifiers(input) ?? validateEmail(input),
                      ),
                      const SizedBox(height: NexoSpace.x3),
                      TextFormField(
                        controller: mobile,
                        keyboardType: TextInputType.phone,
                        textDirection: TextDirection.ltr,
                        textInputAction: TextInputAction.done,
                        decoration: const InputDecoration(
                          labelText: 'شماره همراه',
                          prefixIcon: Icon(Icons.phone_outlined),
                        ),
                        validator: (input) =>
                            validateIdentifiers(input) ?? validateMobile(input),
                      ),
                      const SizedBox(height: NexoSpace.x3),
                      const NexoNotice(
                        'رمز عبور و نقش حساب در این صفحه قابل‌تغییر نیستند.',
                        icon: Icons.lock_outline,
                      ),
                      if (error != null)
                        NexoNotice(error!,
                            icon: Icons.error_outline, color: NexoTheme.danger),
                      if (success != null)
                        NexoNotice(success!,
                            icon: Icons.check_circle_outline,
                            color: NexoTheme.success),
                      FilledButton.icon(
                        onPressed: saving ? null : save,
                        icon: saving
                            ? const SizedBox.square(
                                dimension: 20,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: NexoTheme.background))
                            : const Icon(Icons.save_outlined),
                        label: Text(saving ? 'در حال ذخیره…' : 'ذخیره تغییرات'),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
        bottomNavigationBar: const NexoBottomNavigation(selectedIndex: 3),
      );

  String _status(String? status) => switch (status) {
        'ACTIVE' => 'فعال',
        'SUSPENDED' => 'محدود',
        'BLOCKED' => 'مسدود',
        _ => 'نامشخص',
      };

  String _role(String role) => switch (role) {
        'PLAYER' => 'بازیکن',
        _ => role,
      };
}

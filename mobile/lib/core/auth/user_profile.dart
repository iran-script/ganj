class UserProfile {
  const UserProfile({
    required this.id,
    required this.name,
    this.email,
    this.mobile,
    this.status,
    this.roles = const [],
    this.lastLoginAt,
    this.createdAt,
  });

  final String id;
  final String name;
  final String? email;
  final String? mobile;
  final String? status;
  final List<String> roles;
  final String? lastLoginAt;
  final String? createdAt;

  factory UserProfile.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['name'];
    if (id is! String || name is! String) {
      throw const FormatException('Invalid user profile.');
    }
    return UserProfile(
      id: id,
      name: name,
      email: json['email'] as String?,
      mobile: json['mobile'] as String?,
      status: json['status'] as String?,
      roles: (json['roles'] as List? ?? const []).whereType<String>().toList(),
      lastLoginAt: json['last_login_at'] as String?,
      createdAt: json['created_at'] as String?,
    );
  }

  static Map<String, dynamic> patchPayload({
    required String name,
    required String email,
    required String mobile,
  }) =>
      {
        'name': name.trim(),
        'email': email.trim().isEmpty ? null : email.trim(),
        'mobile': mobile.trim().isEmpty ? null : normalizeDigits(mobile.trim()),
      };

  static String normalizeDigits(String input) => input.replaceAllMapped(
        RegExp(r'[۰-۹٠-٩]'),
        (match) {
          final codePoint = match[0]!.runes.single;
          final digit =
              codePoint >= 0x06f0 ? codePoint - 0x06f0 : codePoint - 0x0660;
          return digit.toString();
        },
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'mobile': mobile,
        'status': status,
        'roles': roles,
        'last_login_at': lastLoginAt,
        'created_at': createdAt,
      };
}

allprojects {
    buildscript.repositories {
        maven { url = uri(System.getenv("NEXO_GOOGLE_MAVEN_MIRROR") ?: "https://maven.aliyun.com/repository/google") }
    }
    repositories {
        maven { url = uri(System.getenv("NEXO_GOOGLE_MAVEN_MIRROR") ?: "https://maven.aliyun.com/repository/google") }
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

// Compile Android plugins against the same installed API level as Flutter 3.47.
subprojects {
    afterEvaluate {
        extensions.findByType<com.android.build.gradle.BaseExtension>()?.apply {
            compileSdkVersion(36)
        }
    }
}

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}

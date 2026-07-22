package trivy

# Suppress every vulnerability attributed to the kernel-headers package,
# BY PACKAGE rather than by CVE id.
#
# Why not an id list: .trivyignore.yaml previously named four specific
# linux-libc-dev CVEs. Debian ships kernel security batches continuously,
# so by the v1.1.0 release seven NEW HIGH advisories existed against the
# same package and the same version — none on the list. An enumerated list
# of kernel CVEs is stale the moment Debian publishes again; it cannot be
# maintained, and its staleness fails the release gate rather than
# reporting anything actionable.
#
# Why suppressing the package is correct: linux-libc-dev ships kernel
# HEADERS for compiling userspace. It is pulled in by libc6-dev, which
# comes from the upstream php:8.4-fpm-bookworm base image itself, not from
# anything this Dockerfile installs. A container does not run its own
# kernel — it uses the host's. These CVEs are real findings for whoever
# operates the host and are structurally inapplicable to this image, which
# merely carries the headers.
#
# Scope is exactly one package. A CRITICAL/HIGH with a fix available in
# ANY other package — including every PHP and JS dependency CraftKeeper
# actually ships — still fails the job and still withholds the moving
# tags. Full results at every severity, including everything suppressed
# here, are still uploaded to the Security tab on every release.

default ignore = false

ignore {
	input.PkgName == "linux-libc-dev"
}

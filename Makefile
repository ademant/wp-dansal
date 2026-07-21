SLUG      := wp-dansal
VERSION   := $(shell awk -F': ' '/^[ \t]*\*[ \t]*Version:/{gsub(/[ \t]+$$/, "", $$2); print $$2; exit}' wp-dansal.php)

# Optional local config for `make deploy` (WP_PLUGIN_DIR, WP_OWNER) — see
# .env.example. Silently absent for everyone who only ever runs zip/build.
-include .env

BUILD_DIR := build
DIST_DIR  := dist
STAGE     := $(BUILD_DIR)/$(SLUG)
ZIP_FILE  := $(DIST_DIR)/$(SLUG)-$(VERSION).zip

# Everything that ships inside the plugin zip, as an explicit allow-list
# (rather than excluding dev cruft) so nothing new — composer.json, phpcs
# config, CI workflows, .git — leaks into a release by accident just because
# it was added to the repo root later.
DIST_FILES := wp-dansal.php uninstall.php includes templates assets languages LICENSE README.md readme.txt

.PHONY: all zip build deploy setup-env clean version help pot mo wp-cli

all: zip

help:
	@echo "make zip      build $(DIST_DIR)/$(SLUG)-<version>.zip (default)"
	@echo "make build    assemble the plugin into $(STAGE)/ without zipping"
	@echo "make deploy   rsync the built plugin into \$$WP_PLUGIN_DIR (see .env.example)"
	@echo "make setup-env  create .env by detecting WP_PLUGIN_DIR/WP_OWNER from a WordPress install"
	@echo "make pot      regenerate languages/$(SLUG).pot via wp-cli"
	@echo "make version  print the detected plugin version ($(VERSION))"
	@echo "make clean    remove $(BUILD_DIR)/ and $(DIST_DIR)/"
	@echo "make mo       compile all .po files to .mo files"
	@echo "make wp-cli   ensure wp-cli is installed"

WP_CLI_PATH ?= $(shell command -v wp 2>/dev/null)
WP_CLI_INSTALLED ?= $(shell test -x "$(WP_CLI_PATH)" && echo yes || echo no)

# Check if MO files need compiling (returns empty if all MO files exist and are newer than PO)
MO_UP_TO_DATE ?= $(shell \
	for po_file in languages/$(SLUG)-*.po; do \
		[ -f "$$po_file" ] || continue; \
		mo_file="$${po_file%.po}.mo"; \
		[ -f "$$mo_file" ] && [ "$$po_file" -ot "$$mo_file" ] || exit 1; \
	done && echo yes || echo no)

wp-cli:
	@if [ "$(WP_CLI_INSTALLED)" = "no" ]; then\
		echo "wp-cli not found. Installing to /usr/local/bin/wp...";\
		curl -sS -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar &&\
		chmod +x /tmp/wp-cli.phar; \
		if [ "$(shell id -u)" = "0" ]; then\
			mv /tmp/wp-cli.phar /usr/local/bin/wp;\
		else\
			sudo mv /tmp/wp-cli.phar /usr/local/bin/wp;\
		fi &&\
		echo "wp-cli installed successfully";\
	else\
		echo "wp-cli already installed at $(WP_CLI_PATH)";\
	fi

# Only compile MO files if they don't exist or are older than PO files
mo: wp-cli
	@if [ "$(MO_UP_TO_DATE)" = "yes" ]; then\
		echo "MO files are up to date";\
	else\
		for po_file in languages/$(SLUG)-*.po; do\
			[ -f "$$po_file" ] || continue;\
			mo_file="$${po_file%.po}.mo";\
			wp i18n make-mo "$$po_file" "$$mo_file" &&\
			echo "Compiled $$mo_file";\
		done;\
	fi

pot: wp-cli
	@wp i18n make-pot . languages/$(SLUG).pot --slug=$(SLUG) --domain=$(SLUG)
	@echo "Regenerated languages/$(SLUG).pot"

version:
	@echo "$(VERSION)"

build:
	@if [ "$(MO_UP_TO_DATE)" != "yes" ]; then\
		$(MAKE) mo;\
	fi
	@test -n "$(VERSION)" || { echo "Could not read Version from wp-dansal.php header" >&2; exit 1; }
	@rm -rf "$(STAGE)"
	@mkdir -p "$(STAGE)"
	@cp -R $(DIST_FILES) "$(STAGE)/"
	@find "$(BUILD_DIR)" -name '.DS_Store' -delete
	@echo "Staged plugin in $(STAGE)"

zip: build
	@mkdir -p "$(DIST_DIR)"
	@rm -f "$(ZIP_FILE)"
	@cd "$(BUILD_DIR)" && zip -rq "../$(ZIP_FILE)" "$(SLUG)"
	@echo "Built $(ZIP_FILE)"

deploy: build
	@test -n "$(WP_PLUGIN_DIR)" || { echo "WP_PLUGIN_DIR is not set (copy .env.example to .env and fill it in)" >&2; exit 1; }
	@test -n "$(WP_OWNER)" || { echo "WP_OWNER is not set (copy .env.example to .env and fill it in)" >&2; exit 1; }
	@rsync -a --delete "$(STAGE)/" "$(WP_PLUGIN_DIR)/$(SLUG)/"
	@sudo chown -R "$(WP_OWNER)" "$(WP_PLUGIN_DIR)/$(SLUG)"
	@echo "Deployed to $(WP_PLUGIN_DIR)/$(SLUG) (owner $(WP_OWNER))"

setup-env:
	@test -f .env && { echo ".env already exists -- remove it first if you want to redetect" >&2; exit 1; } || true
	@printf 'Path to WordPress install (contains wp-content/): '; \
	read wp_root; \
	wp_root=$${wp_root%/}; \
	plugin_dir="$$wp_root/wp-content/plugins"; \
	test -d "$$plugin_dir" || { echo "Error: $$plugin_dir not found" >&2; exit 1; }; \
	owner=$$(stat -c '%U:%G' "$$plugin_dir" 2>/dev/null || stat -f '%Su:%Sg' "$$plugin_dir" 2>/dev/null); \
	test -n "$$owner" || { echo "Error: could not determine owner of $$plugin_dir" >&2; exit 1; }; \
	printf 'WP_PLUGIN_DIR=%s\nWP_OWNER=%s\n' "$$plugin_dir" "$$owner" > .env; \
	echo "Wrote .env: WP_PLUGIN_DIR=$$plugin_dir WP_OWNER=$$owner"

clean:
	@rm -rf "$(BUILD_DIR)" "$(DIST_DIR)"

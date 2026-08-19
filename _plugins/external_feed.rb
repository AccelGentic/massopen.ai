require 'feedjira'
require 'httparty'

# Pulls the latest posts from the Ghost newsletter into a Jekyll collection so
# the home page can list them.
#
# The newsletter is a separate service, so it WILL occasionally be unreachable
# — a restart, a DNS blip, an expired certificate. None of that should be able
# to stop the website from building, so every failure here degrades instead of
# raising:
#
#   1. fetch the live feed; if it parses, cache it and use it
#   2. otherwise fall back to the last good copy on disk
#   3. otherwise render the section with no items
#
# The cache is what makes this worth doing. Without it an outage would quietly
# empty the news section on the next deploy; with it the site keeps showing the
# most recent posts it ever saw.
module Jekyll
  class ExternalFeedDisplay < Generator
    safe true
    priority :high

    DEFAULT_FEED_URL = 'https://news.massopen.ai/rss'.freeze
    CACHE_NAME  = 'external_feed.xml'.freeze
    HTTP_TIMEOUT = 10 # seconds; a hung feed must not hang the build

    # Overridable from _config.yml as `news_feed_url`, which also makes the
    # fallback behaviour testable against a local feed.
    def feed_url(site)
      site.config['news_feed_url'] || DEFAULT_FEED_URL
    end

    def generate(site)
      # Register the collection unconditionally. Templates iterate
      # site.external_feed, so it has to exist even when there is nothing in it.
      collection = Jekyll::Collection.new(site, 'external_feed')
      site.collections['external_feed'] = collection

      entries(site).each do |entry|
        doc = build_document(site, collection, entry)
        collection.docs << doc unless doc.nil?
      end
    end

    private

    def log_warn(message)
      Jekyll.logger.warn 'External Feed:', message
    end

    def cache_path(site)
      dir = File.join(site.source, site.config['cache_dir'] || '.jekyll-cache')
      File.join(dir, CACHE_NAME)
    end

    # Returns the feed entries, from the network if possible and the cache if
    # not. Never raises.
    def entries(site)
      body = fetch(site)

      if body
        feed = parse(body)
        if feed
          write_cache(site, body)
          return feed.entries || []
        end
        log_warn "#{feed_url(site)} responded but the body was not a usable feed."
      end

      cached = read_cache(site)
      if cached
        feed = parse(cached)
        if feed
          age = ((Time.now - File.mtime(cache_path(site))) / 3600).round
          log_warn "Using the cached feed from ~#{age}h ago. The site will " \
                   'build, but the news section may be stale.'
          return feed.entries || []
        end
        log_warn 'The cached feed could not be parsed either.'
      end

      log_warn 'No feed available. The news section will render without items.'
      []
    end

    def fetch(site)
      url = feed_url(site)
      response = HTTParty.get(
        url,
        timeout: HTTP_TIMEOUT,
        headers: { 'User-Agent' => 'massopen.ai site build' }
      )

      unless response.success?
        log_warn "#{url} returned HTTP #{response.code}."
        return nil
      end

      response.body
    rescue StandardError => e
      # Timeouts, DNS, TLS, connection refused — all non-fatal here.
      log_warn "Could not reach #{url}: #{e.class}: #{e.message}"
      nil
    end

    def parse(body)
      return nil if body.nil? || body.strip.empty?

      Feedjira.parse(body)
    rescue StandardError => e
      # Feedjira::NoParserAvailable when the body is not a feed at all, which
      # is what a captive portal or proxy error page looks like.
      log_warn "Could not parse the feed: #{e.class}: #{e.message}"
      nil
    end

    def write_cache(site, body)
      path = cache_path(site)
      FileUtils.mkdir_p(File.dirname(path))
      File.write(path, body)
    rescue StandardError => e
      # A read-only checkout should not fail the build either.
      log_warn "Could not write the feed cache: #{e.message}"
    end

    def read_cache(site)
      path = cache_path(site)
      File.exist?(path) ? File.read(path) : nil
    rescue StandardError => e
      log_warn "Could not read the feed cache: #{e.message}"
      nil
    end

    def build_document(site, collection, entry)
      title = entry.title.to_s.strip
      return nil if title.empty?

      slug = title.downcase.gsub(/[^a-z0-9]/, '-').squeeze('-').gsub(/\A-|-\z/, '')
      slug = 'post' if slug.empty?
      path = File.join(site.source, '_external_feed', "#{slug}.md")

      doc = Jekyll::Document.new(path, site: site, collection: collection)
      doc.data['title']   = title
      doc.data['date']    = entry.published || Time.now
      doc.data['link']    = entry.url
      doc.data['excerpt'] = entry.summary
      doc
    rescue StandardError => e
      # One malformed entry should not lose the whole feed.
      log_warn "Skipping an entry: #{e.class}: #{e.message}"
      nil
    end
  end
end

require 'feedjira'
require 'httparty'

module Jekyll
  class ExternalFeedDisplay < Generator
    safe true
    priority :high

    def generate(site)
      # Create a new custom Jekyll collection named 'external_feed'
      jekyll_coll = Jekyll::Collection.new(site, 'external_feed')
      site.collections['external_feed'] = jekyll_coll

      # Fetch and parse your target RSS or Atom feed
      feed_url = "https://news.massopen.ai/rss" 
      news_rss = HTTParty.get(feed_url).body
      news_feed = Feedjira.parse(news_rss)

      begin
        news_feed.entries.each do |entry|
          # Create a sanitized filename for each feed article
          filename = entry.title.downcase.gsub(/[^a-z0-9]/, '-').chomp('-') + ".md"
          path = File.join(site.source, "_external_feed", filename)
          
          # Map feed content to Jekyll document front matter
          doc = Jekyll::Document.new(path, { :site => site, :collection => jekyll_coll })
          doc.data['title'] = entry.title
          doc.data['date']  = entry.published
          doc.data['link']  = entry.url
          doc.data['excerpt'] = entry.summary

          jekyll_coll.docs << doc
        end
      rescue => e
        Jekyll.logger.warn "External Feed Plugin:", "Failed to fetch or parse feed from #{feed_url}: #{e.message}"
      end
    end
  end
end

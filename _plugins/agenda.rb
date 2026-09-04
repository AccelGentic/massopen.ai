# Builds the public agenda from _data/agenda.yml, which tools/agenda.php
# exports from the database.
#
#   /agenda/                        every event
#   /agenda/<event>/                that event's running order
#   /agenda/<event>/<talk>/         one talk: speaker, bio, event, abstract
#
# Real pages rather than a JavaScript widget, so every talk has a URL a speaker
# can share and a search engine can index.
#
# Absent or empty data is normal — before the first export, or between events —
# so this generates nothing and leaves the site to build as usual.
module Jekyll
  class AgendaGenerator < Generator
    safe true
    priority :normal

    # A sponsor blurb is meant to be a sentence or two. Nothing truncates it —
    # these are someone else's words about why they are paying for the room —
    # so say so at build time and leave the copy alone.
    BLURB_MAX = 300

    def generate(site)
      check_sponsors(site)

      data = site.data['agenda']
      events = data.is_a?(Hash) ? data['events'] : nil
      return if events.nil? || events.empty?

      events = events.select { |e| e['slug'].to_s != '' }

      site.pages << AgendaIndex.new(site, events)

      events.each do |event|
        site.pages << EventPage.new(site, event)
        (event['talks'] || []).each do |talk|
          next if talk['slug'].to_s == ''
          site.pages << TalkPage.new(site, event, talk)
        end
      end
    end

    # Sponsors are authored by hand in _data/events.yml, so the only thing
    # standing between a typo and a published page is this.
    def check_sponsors(site)
      events = site.data['events']
      return unless events.is_a?(Array)

      events.each do |event|
        sponsors = event.is_a?(Hash) ? event['sponsors'] : nil
        next unless sponsors.is_a?(Array)

        where = event['slug'].to_s == '' ? 'an event with no slug' : event['slug']

        sponsors.each_with_index do |sponsor, i|
          unless sponsor.is_a?(Hash) && sponsor['name'].to_s.strip != ''
            Jekyll.logger.warn 'Agenda:',
              "#{where}: sponsor #{i + 1} has no name, so it is not shown."
            next
          end

          blurb = sponsor['blurb'].to_s
          next if blurb.length <= BLURB_MAX

          Jekyll.logger.warn 'Agenda:',
            "#{where}: #{sponsor['name']}'s blurb is #{blurb.length} characters, " \
            "over the #{BLURB_MAX} the callout is designed for. Shown in full."
        end
      end
    end
  end

  # Shared plumbing: a page built in memory rather than read from disk.
  class GeneratedPage < Page
    def initialize(site, dir, name)
      @site = site
      @base = site.source
      @dir  = dir
      @name = name
      process(@name)
      self.data = {}
    end
  end

  class AgendaIndex < GeneratedPage
    def initialize(site, events)
      super(site, '/agenda', 'index.html')
      data['layout']      = 'default'
      data['title']       = 'Agenda — Mass Open'
      data['description'] = 'Talks and speakers at Mass Open events.'
      data['events']      = events
      self.content = "{% include agenda/index.html %}"
    end
  end

  class EventPage < GeneratedPage
    def initialize(site, event)
      super(site, "/agenda/#{event['slug']}", 'index.html')
      data['layout']      = 'default'
      data['title']       = "#{event['title']} — Mass Open"
      data['description'] = "Agenda for #{event['title']}."
      data['event']       = event
      self.content = "{% include agenda/event.html %}"
    end
  end

  class TalkPage < GeneratedPage
    def initialize(site, event, talk)
      super(site, "/agenda/#{event['slug']}/#{talk['slug']}", 'index.html')
      data['layout']      = 'default'
      data['title']       = "#{talk['topic']} — Mass Open"
      data['description'] = "#{talk['topic']}, presented by #{talk['speaker']}."
      data['event']       = event
      data['talk']        = talk
      self.content = "{% include agenda/talk.html %}"
    end
  end
end

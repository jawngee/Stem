<?php

namespace ILab\Stem\Models;


use ILab\Stem\Core\Context;
use ILab\Stem\Utilities\Text;

class Post extends WordPressModel
{
    public $id;
    public $post_name;

    protected $post;
    public $context;

    private $author = null;
    private $topCategory = null;
    private $topCategories = null;
    private $categories = null;
    private $tags = null;
    private $permalink = null;
    private $thumbnail = null;
    private $slug = null;


    public function __construct(Context $context, \WP_Post $post)
    {
        $this->id = $post->ID;
        $this->post_name=$post->post_name;
        $this->context = $context;
        $this->post = $post;
        $this->slug = $post->post_name;
    }

    public function author()
    {
        if ($this->author)
            return $this->author;

        if ($this->post->post_author)
            ;
        $this->author = new \WP_User($this->post->post_author);

        return $this->author;
    }

    public function categories()
    {
        if ($this->categories)
            return $this->categories;

        $categories = wp_get_post_categories($this->post->ID);
        if ($categories && (count($categories) > 0))
        {
            $this->categories = [];
            foreach ($categories as $categoryID)
            {
                $this->categories[] = Term::term($this->context, $categoryID, 'category');
            }
        }

        return $this->categories;
    }

    public function slug() {
        if ($this->slug)
            return $this->slug;


    }

    public function topCategory()
    {
        if ($this->topCategory)
            return $this->topCategory;

        $cats = $this->categories();
        if ($cats)
        {
            $this->topCategories = [];
            foreach ($cats as $category)
            {
                $parent = $category->parent;
                if ($parent == null)
                    $this->topCategories[] = $category;
                else
                {
                    while (true)
                    {
                        if ($parent->parent == null)
                        {
                            $this->topCategories[] = $parent;
                            break;
                        }
                        else
                            $parent = $parent->parent;
                    }
                }
            }

            if (count($this->topCategories) > 0)
                $this->topCategory = $this->topCategories[0];
        }

        return $this->topCategory;
    }

    public function tags()
    {
        if ($this->tags)
            return $this->tags;

        $this->tags = [];
        $tags = wp_get_post_tags($this->post->ID);
        if ($tags && (count($tags) > 0))
        {
            foreach ($tags as $tag)
            {
                $this->tags[] = Term::termFromTermData($this->context, $tag);
            }
        }

        return $this->tags;
    }

    public function title()
    {
        return $this->post->post_title;
    }

    public function type()
    {
        return $this->post->post_type;
    }

    public function content($dropcap = false)
    {
        $content = apply_filters('the_content', $this->post->post_content);

        return $content;
    }

    public function date($format = 'd/M/Y')
    {
        return mysql2date($format, $this->post->post_date);
    }

    public function thumbnail()
    {
        if ($this->thumbnail)
            return $this->thumbnail;

        $thumb_id = get_post_thumbnail_id($this->post->ID);
        if ($thumb_id)
            $this->thumbnail = $this->context->modelForPost(\WP_Post::get_instance($thumb_id));

        return $this->thumbnail;
    }

    public function excerpt($len = 50, $force = false, $readmore = 'Read More', $strip = true)
    {
        $text = '';
        $trimmed = false;
        if (isset($this->post->post_excerpt) && strlen($this->post->post_excerpt))
        {
            if ($force)
            {
                $text = Text::trim($this->post->post_excerpt, $len, false);
                $trimmed = true;
            }
            else
            {
                $text = $this->post->post_excerpt;
            }
        }
        if (!strlen($text) && strpos($this->post->post_content, '<!--more-->') !== false)
        {
            $pieces = explode('<!--more-->', $this->post->post_content);
            $text = $pieces[0];
            if ($force)
            {
                $text = Text::trim($text, $len, false);
                $trimmed = true;
            }
        }
        if (!strlen($text))
        {
            $text = Text::trim($this->content(), $len, false);
            $trimmed = true;
        }
        if (!strlen(trim($text)))
        {
            return trim($text);
        }
        if ($strip)
        {
            $text = trim(strip_tags($text));
        }
        if (strlen($text))
        {
            $text = trim($text);
            $last = $text[strlen($text) - 1];
            if ($last != '.' && $trimmed)
            {
                $text .= ' &hellip; ';
            }
            if (!$strip)
            {
                $last_p_tag = strrpos($text, '</p>');
                if ($last_p_tag !== false)
                {
                    $text = substr($text, 0, $last_p_tag);
                }
                if ($last != '.' && $trimmed)
                {
                    $text .= ' &hellip; ';
                }
            }

            if ($readmore)
            {
                $text .= ' <a href="' . $this->permalink() . '" class="read-more">' . $readmore . '</a>';
            }

            if (!$strip)
            {
                $text .= '</p>';
            }
        }
        return trim($text);
    }

    public function permalink()
    {
        if ($this->permalink)
            return $this->permalink;

        $this->permalink=$this->context->permalink($this->id);

        return $this->permalink;
    }

    public function field($field)
    {
        return get_field($field, $this->post->ID);
    }

    public function related($postTypes, $limit)
    {
        global $wpdb;
        array_walk($postTypes, function (&$value, $index)
        {
            $value = "'$value'";
        });
        $postTypes = implode(',', $postTypes);

        $query = <<<"QUERY"
SELECT WP.ID, COUNT(*) AS tag_count, rand() as random FROM  wp_term_relationships T1 JOIN  wp_term_relationships T2
ON
	T1.term_taxonomy_id = T2.term_taxonomy_id
AND
	T1.object_id != T2.object_id
JOIN
	wp_posts WP
ON
	T2.object_id = WP.ID
WHERE
	T1.object_id = {$this->id}
    AND WP.post_status='publish'
    and WP.post_type in ($postTypes)
GROUP BY
	T2.object_id
ORDER BY COUNT(*) DESC, random desc
limit $limit
QUERY;

        $results=$wpdb->get_results($query);
        $related=[];
        if ($results) {
            foreach($results as $result)
            {
                $related[]=$this->context->modelForPost(\WP_Post::get_instance($result->ID));
            }
        }

        return $related;
    }

    public function __debugInfo() {
        return [
            'id'=>$this->id,
            'post_name'=>$this->post_name,
            'title'=>$this->post->post_title,
            'categories'=>$this->categories(),
            'tags'=>$this->tags()
        ];
    }

    public function jsonSerialize() {
        return [
            'type'=>$this->post->post_type,
            'title'=>$this->title(),
	        'author'=>$this->author()->display_name,
            'date'=>$this->date('d/M/Y g:i a'),
            'content'=>$this->content(false),
            'excerpt'=>$this->excerpt(),
            'url'=>$this->permalink(),
            'mime_type'=>$this->post->post_mime_type,
            'thumbnail'=>$this->thumbnail(),
            'categories'=>$this->categories(),
            'tags'=>$this->tags()
        ];
    }

}
